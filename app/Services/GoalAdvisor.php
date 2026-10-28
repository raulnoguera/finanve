<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Household;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Plan realista para las metas: capacidad de ahorro según ingresos y gastos reales,
 * con margen para imprevistos, y recomendaciones numéricas (sin prometer lo que los datos no sostienen).
 */
class GoalAdvisor
{
    /** Parte del ahorro promedio que se considera "usable"; el resto queda como colchón para imprevistos. */
    public const SAFETY = 0.8;

    public function __construct(private FinanceService $fin, private FixedExpenseService $fixed) {}

    /** Situación financiera de la familia en términos de capacidad de ahorro. */
    public function context(Household $h): array
    {
        $now = now();
        $stats = collect(range(1, 3))
            ->map(fn ($i) => $this->fin->month($h, $now->copy()->subMonthsNoOverflow($i)->startOfMonth()))
            ->filter(fn ($s) => $s['income'] > 0 || $s['expense'] > 0)->values();

        $months = $stats->count();
        $current = $this->fin->month($h, $now);
        $basis = $months ? $stats : collect([$current]);

        // Lo que aún falta por pagar este mes: fijos sin pagar + gastos registrados por pagar (hasta fin de mes)
        $pendingFixed = (float) $this->fixed->totals($this->fixed->month($h, $now))['pending'];
        $plannedPending = (float) $this->fin->forecast($h, $now, 0.0, 0.0)['planned'];

        $incomeAvg = (float) $basis->avg('income');
        $expenseAvg = (float) $basis->avg('expense');
        // Sin meses anteriores completos, el mes en curso aún no refleja lo que vas a pagar: se suma lo pendiente
        if (! $months) {
            $expenseAvg += $pendingFixed + $plannedPending;
        }
        $surplusAvg = $incomeAvg - $expenseAvg;

        // Lo que realmente te queda este mes: ingresos − gastos − apartado − lo que aún falta por pagar
        $incomeNow = $current['income'] > 0 ? $current['income'] : $incomeAvg;
        $remainingNow = $incomeNow - $current['expense'] - $current['saved'] - $pendingFixed - $plannedPending;

        // Gasto variable promedio por categoría (excluye los pagos de gastos fijos): dónde se puede recortar
        $from = $months ? $now->copy()->subMonthsNoOverflow(3)->startOfMonth() : $now->copy()->startOfMonth();
        $to = $months ? $now->copy()->subMonthNoOverflow()->endOfMonth() : $now->copy()->endOfMonth();
        $divisor = max(1, $months);
        $variable = $h->transactions()->with('category.parent')->where('type', 'expense')->whereNull('fixed_month')
            ->whereBetween('date', [$from, $to])->get()
            ->groupBy(fn ($t) => $t->category?->parent?->name ?? $t->category?->name ?? 'Sin categoría')
            ->map(fn ($g) => round($g->sum('amount') / $divisor, 2))
            ->filter(fn ($v) => $v > 0)->sortDesc();

        return [
            'months' => $months,
            'confidence' => $months >= 2 ? 'alta' : ($months === 1 ? 'media' : 'baja'),
            'income_avg' => $incomeAvg,
            'expense_avg' => $expenseAvg,
            'surplus_avg' => $surplusAvg,
            'capacity' => max(0.0, $surplusAvg) * self::SAFETY,
            'buffer' => max(0.0, $surplusAvg) * (1 - self::SAFETY),
            'this_income' => $incomeNow,
            'this_income_estimated' => $current['income'] <= 0,
            'this_expense' => $current['expense'],
            'this_saved' => $current['saved'],
            'pending_fixed' => $pendingFixed,
            'pending_planned' => $plannedPending,
            'remaining_now' => $remainingNow,
            'variable' => $variable,
        ];
    }

    /** Plan para cada meta: viabilidad, aporte mensual realista, aporte sugerido este mes y recomendaciones. */
    public function plans(Household $h, Collection $goals): array
    {
        $ctx = $this->context($h);
        $capacity = $ctx['capacity'];
        $open = $goals->whereNull('completed_at');

        // Cuánto exigen en total las metas con fecha (para repartir la capacidad sin sobrecomprometerla)
        $needs = [];
        foreach ($open as $g) {
            $remaining = max(0, (float) $g->target_amount - $g->savedAmount());
            $monthsLeft = $g->deadline ? max(1, (int) ceil(now()->floatDiffInMonths($g->deadline, false))) : null;
            $needs[$g->id] = $g->deadline && $remaining > 0 ? $remaining / $monthsLeft : 0.0;
        }
        $sumNeeds = array_sum($needs);
        $undated = $open->filter(fn ($g) => ! $g->deadline && max(0, (float) $g->target_amount - $g->savedAmount()) > 0)->count();
        $leftover = max(0.0, $capacity - min($capacity, $sumNeeds));

        $plans = [];
        foreach ($goals as $g) {
            $saved = $g->savedAmount();
            $target = (float) $g->target_amount;
            $remaining = max(0.0, $target - $saved);
            $plan = [
                'saved' => $saved, 'target' => $target, 'remaining' => $remaining,
                'pct' => $target > 0 ? min(100, (int) round($saved / $target * 100)) : 0,
                'months_left' => null, 'required' => null, 'allocation' => null,
                'eta_months' => null, 'eta_date' => null, 'gap' => null,
                'this_month' => 0.0, 'status' => 'done', 'tips' => [], 'scenarios' => [],
            ];

            if ($g->completed_at || $remaining <= 0.004) {
                $plans[$g->id] = $plan;
                continue;
            }

            $monthsLeft = $g->deadline ? max(1, (int) ceil(now()->floatDiffInMonths($g->deadline, false))) : null;
            $required = $g->deadline ? $remaining / $monthsLeft : null;
            $plan['months_left'] = $monthsLeft;
            $plan['required'] = $required;

            // Parte de la capacidad que le toca a esta meta
            if ($g->deadline) {
                $alloc = $sumNeeds <= $capacity ? $required : ($sumNeeds > 0 ? $capacity * $required / $sumNeeds : 0.0);
            } else {
                $alloc = $undated > 0 ? $leftover / $undated : 0.0;
            }
            $plan['allocation'] = $alloc;

            // Veredicto
            if ($ctx['surplus_avg'] <= 0) {
                $status = 'sin_capacidad';
            } elseif (! $g->deadline) {
                // Con margen positivo pero toda la capacidad ya asignada a metas con fecha
                $status = $alloc > 0.004 ? 'sin_fecha' : 'comprometida';
            } elseif ($g->deadline->isPast()) {
                $status = 'vencida';
            } elseif ($required <= $alloc + 0.004) {
                $status = $required <= $capacity * 0.5 ? 'holgada' : 'ajustada';
            } else {
                $status = 'no_viable';
            }
            $plan['status'] = $status;

            // Ritmo realista y fecha estimada
            $rate = $g->deadline && in_array($status, ['holgada', 'ajustada'], true) ? $required : $alloc;
            if ($rate > 0.004) {
                $plan['eta_months'] = (int) ceil($remaining / $rate);
                $plan['eta_date'] = now()->addMonthsNoOverflow($plan['eta_months']);
            }
            if ($status === 'no_viable' && $required !== null) {
                $plan['gap'] = $required - $alloc;
            }

            // Aporte sugerido para ESTE mes: no más de lo necesario ni más del 70 % de lo que realmente te queda
            // Si el plazo no es viable o no hay margen, se sugiere lo que realmente se puede (nunca más de la asignación)
            $want = in_array($status, ['holgada', 'ajustada'], true) ? (float) $required : $alloc;
            $room = max(0.0, $ctx['remaining_now']) * 0.7;
            $plan['this_month'] = $status === 'sin_capacidad' ? 0.0 : round(min($want, $room, $remaining), 2);

            // Plazos posibles: cuánto habría que apartar al mes según el plazo elegido
            foreach ([3, 6, 9, 12, 18, 24, 36] as $m) {
                $per = $remaining / $m;
                if ($capacity > 0 && $per > $capacity * 3) {
                    continue;   // demasiado lejos de lo posible: no se muestra
                }
                $plan['scenarios'][] = ['months' => $m, 'per_month' => $per, 'ok' => $capacity > 0 && $per <= $capacity + 0.004,
                    'comfortable' => $capacity > 0 && $per <= $capacity * 0.5];
            }

            $plan['tips'] = $this->tips($ctx, $plan, $g, $rate ?? 0.0);
            $plans[$g->id] = $plan;
        }

        return [
            'context' => $ctx,
            'plans' => $plans,
            'committed' => min($capacity, $sumNeeds),
            'overcommitted' => $sumNeeds > $capacity + 0.004 && $open->count() > 0,
            'sum_needs' => $sumNeeds,
        ];
    }

    /** Recomendaciones concretas y con números para llegar antes (o para que sea posible). */
    private function tips(array $ctx, array $plan, Goal $g, float $rate): array
    {
        $tips = [];
        $remaining = $plan['remaining'];

        // 1) Recortes en tus gastos variables (15 % de las 2 categorías más grandes)
        $cuts = $ctx['variable']->take(2)->map(fn ($avg, $cat) => ['cat' => $cat, 'avg' => $avg, 'free' => round($avg * 0.15, 2)])
            ->filter(fn ($c) => $c['free'] >= 1)->values();
        $extraCuts = (float) $cuts->sum('free');

        foreach ($cuts as $c) {
            $line = "Reducir {$c['cat']} un 15 % (de ".money($c['avg']).' a '.money($c['avg'] - $c['free']).' al mes) libera '.money($c['free']).'/mes';
            if ($rate > 0.004) {
                $new = (int) ceil($remaining / ($rate + $c['free']));
                $base = $plan['eta_months'] ?? $new;
                if ($new < $base) {
                    $line .= " y adelanta la meta ".($base - $new).' '.($base - $new === 1 ? 'mes' : 'meses').'.';
                } else {
                    $line .= '.';
                }
            } else {
                $line .= '.';
            }
            $tips[] = ['icon' => 'pie', 'text' => $line];
        }

        // 2) Cerrar la brecha cuando el plazo no es viable
        if ($plan['status'] === 'no_viable' && $plan['gap'] > 0) {
            $gap = $plan['gap'];
            $txt = 'Para llegar en la fecha elegida te faltan '.money($gap).'/mes. ';
            $txt .= $extraCuts >= $gap
                ? 'Con los recortes anteriores lo cubrirías.'
                : ($extraCuts > 0 ? 'Los recortes anteriores cubren '.money($extraCuts).'; el resto ('.money($gap - $extraCuts).') tendría que venir de ingresos extra.' : 'Tendría que venir de ingresos extra o de gastar menos.');
            $tips[] = ['icon' => 'alert', 'text' => $txt];
            if ($plan['eta_date']) {
                $tips[] = ['icon' => 'clock', 'text' => 'Con tu ritmo realista ('.money($plan['allocation']).'/mes) la alcanzarías hacia '.$plan['eta_date']->translatedFormat('F \\d\\e Y').'. Ampliar el plazo es la opción más segura.'];
            }
        }

        // 3) Un aporte extra único acelera
        if ($rate > 0.004) {
            $extra = round(max(1, $remaining * 0.1), 2);
            $weeks = (int) round($extra / $rate * 4.33);
            if ($weeks >= 1) {
                $tips[] = ['icon' => 'plus', 'text' => 'Un aporte extra de '.money($extra).' (un bono o un ingreso eventual) la adelanta unas '.$weeks.' '.($weeks === 1 ? 'semana' : 'semanas').'.'];
            }
        }

        // 4) Hábito: apartar al cobrar
        if ($plan['this_month'] > 0) {
            $tips[] = ['icon' => 'check', 'text' => 'Aparta el aporte apenas cobres, antes de gastar: es la forma más fiable de cumplir una meta.'];
        }

        // 5) Aviso de realismo cuando los datos son pocos
        if ($ctx['confidence'] !== 'alta') {
            $tips[] = ['icon' => 'clock', 'text' => $ctx['months'] === 0
                ? 'Todavía hay muy pocos datos: estas cifras mejoran después de registrar un par de meses completos.'
                : 'Solo hay 1 mes completo de datos: tómalo como una guía y vuelve a revisarlo el mes próximo.'];
        }

        return $tips;
    }
}
