<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Household;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    /** Totales y desglose de un mes (todo en USD). */
    public function month(Household $h, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $base = $h->transactions()->whereBetween('date', [$from, $to]);

        $income = (float) (clone $base)->where('type', 'income')->sum('amount');
        $expense = (float) (clone $base)->where('type', 'expense')->sum('amount');
        $saved = (float) (clone $base)->where('type', 'saving')->sum('amount');   // apartado para metas

        $byCategory = (clone $base)->where('transactions.type', 'expense')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->leftJoin('categories as parents', 'parents.id', '=', 'categories.parent_id')
            ->select(
                // Se agrupa por categoría principal: los gastos de subcategorías suman a su madre.
                DB::raw("COALESCE(parents.name, categories.name, 'Sin categoría') as name"),
                DB::raw("COALESCE(parents.color, categories.color, '#94a3b8') as color"),
                DB::raw('SUM(transactions.amount) as total')
            )
            ->groupByRaw("COALESCE(parents.name, categories.name, 'Sin categoría'), COALESCE(parents.color, categories.color, '#94a3b8')")
            ->orderByDesc('total')->get();

        $daily = (clone $base)->where('type', 'expense')
            ->select('date', DB::raw('SUM(amount) as total'))
            ->groupBy('date')->pluck('total', 'date')
            ->mapWithKeys(fn ($v, $k) => [Carbon::parse($k)->day => (float) $v]);

        return [
            'income' => $income,
            'expense' => $expense,
            'saved' => $saved,
            // Lo que te queda: ingresos − gastos − lo que apartaste para metas
            'balance' => $income - $expense - $saved,
            'free' => $income - $expense,
            'savings_rate' => $income > 0 ? round((($income - $expense) / $income) * 100) : null,
            'by_category' => $byCategory,
            'daily' => $daily,
            'days' => $month->daysInMonth,
        ];
    }

    /**
     * Saldo que tienes y saldo proyectado del mes: descuenta lo que aún falta por pagar
     * (gastos registrados por pagar con fecha hasta fin de este mes + gastos fijos sin pagar).
     * Un gasto con fecha de un mes posterior NO se descuenta aquí, sino en el mes que le corresponde.
     */
    public function forecast(Household $h, Carbon $month, float $balance, float $fixedPending): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $pending = $h->transactions()->withoutGlobalScope('paid')->where('status', 'pending')->where('type', 'expense')
            ->with('category.parent');

        // Mes en curso: también arrastra lo vencido de meses anteriores que sigue sin pagarse
        $inMonth = (clone $pending)->whereDate('date', '<=', $to->toDateString())
            ->when(! $month->isSameMonth(now()), fn ($q) => $q->whereDate('date', '>=', $from->toDateString()))
            ->orderBy('date')->get();

        $later = (clone $pending)->whereDate('date', '>', $to->toDateString())->orderBy('date')->get();

        $planned = round((float) $inMonth->sum('amount'), 2);

        return [
            'balance' => $balance,
            'planned' => $planned,
            'planned_items' => $inMonth,
            'fixed_pending' => round($fixedPending, 2),
            'committed' => round($planned + $fixedPending, 2),
            'projected' => round($balance - $planned - $fixedPending, 2),
            'later' => $later,
            'later_total' => round((float) $later->sum('amount'), 2),
        ];
    }

    /**
     * Presupuesto que aún no has gastado: lo que "se viene" en categorías variables (gasolina, mercado…).
     * Por categoría con tope: tope − gastado − ya comprometido (fijos sin pagar y gastos por pagar de esa categoría).
     * Si una categoría principal y su subcategoría tienen tope, solo cuenta la principal.
     *
     * @param  \Illuminate\Support\Collection  $budgets  categorías con monthly_budget (con parent)
     * @param  array  $spent  spentRollup (category_id => gastado)
     * @param  \Illuminate\Support\Collection  $unpaidFixed  filas de gastos fijos sin pagar del mes (fixed, my)
     * @param  \Illuminate\Support\Collection  $plannedItems  gastos por pagar del mes (con category)
     */
    public function budgetAhead(Carbon $month, $budgets, array $spent, $unpaidFixed, $plannedItems): array
    {
        if ($month->copy()->endOfMonth()->lt(today())) {
            return ['total' => 0.0, 'items' => collect()];   // un mes ya terminado no tiene "por venir"
        }

        $ids = $budgets->pluck('id');
        $items = $budgets->filter(fn ($c) => ! ($c->parent_id && $ids->contains($c->parent_id)))->map(function ($c) use ($spent, $unpaidFixed, $plannedItems) {
            $match = fn ($cat) => $cat && ($cat->id === $c->id || $cat->parent_id === $c->id);
            $fixed = (float) $unpaidFixed->filter(fn ($r) => $match($r['fixed']->category))->sum(fn ($r) => (float) $r['my']);
            $planned = (float) $plannedItems->filter(fn ($t) => $match($t->category))->sum('amount');
            $budget = (float) $c->monthly_budget;
            $used = (float) ($spent[$c->id] ?? 0);

            return [
                'name' => $c->fullName(), 'budget' => $budget, 'spent' => $used, 'committed' => round($fixed + $planned, 2),
                'left' => round(max(0.0, $budget - $used - $fixed - $planned), 2),
            ];
        })->filter(fn ($i) => $i['left'] > 0.004)->sortByDesc('left')->values();

        return ['total' => round((float) $items->sum('left'), 2), 'items' => $items];
    }

    /** Gasto del mes por category_id, para presupuestos. */
    public function spentByCategory(Household $h, Carbon $month): array
    {
        return $h->transactions()->where('type', 'expense')
            ->whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->select('category_id', DB::raw('SUM(amount) as total'))
            ->groupBy('category_id')->pluck('total', 'category_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    /**
     * Gasto del mes por categoría con roll-up: una categoría principal incluye lo de sus subcategorías.
     * Devuelve [category_id => total].
     */
    public function spentRollup(Household $h, Carbon $month): array
    {
        $direct = $this->spentByCategory($h, $month);
        $total = $direct;

        foreach ($h->categories()->whereNotNull('parent_id')->get(['id', 'parent_id']) as $c) {
            $total[$c->parent_id] = ($total[$c->parent_id] ?? 0) + ($direct[$c->id] ?? 0);
        }

        return $total;
    }

    /** Ingresos del mes por categoría: ['direct' => [id => total], 'rollup' => [id => total con subcategorías]]. */
    public function incomeByCategory(Household $h, Carbon $month): array
    {
        $direct = $h->transactions()->where('type', 'income')
            ->whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->select('category_id', DB::raw('SUM(amount) as total'))->groupBy('category_id')
            ->pluck('total', 'category_id')->map(fn ($v) => (float) $v)->all();

        $rollup = $direct;
        foreach ($h->categories()->where('type', 'income')->whereNotNull('parent_id')->get(['id', 'parent_id']) as $c) {
            $rollup[$c->parent_id] = ($rollup[$c->parent_id] ?? 0) + ($direct[$c->id] ?? 0);
        }

        return ['direct' => $direct, 'rollup' => $rollup];
    }

    /** Ahorro mensual promedio (ingresos − gastos) de los últimos N meses completos con movimientos. */
    public function averageMonthlySurplus(Household $h, int $months = 3): float
    {
        $start = now()->subMonths($months)->startOfMonth();
        $end = now()->subMonth()->endOfMonth();

        $rows = $h->transactions()->whereBetween('date', [$start, $end])
            ->select(
                DB::raw("SUM(CASE WHEN type='income' THEN amount WHEN type='expense' THEN -amount ELSE 0 END) as net"),
                DB::raw($this->monthExpr().' as ym')
            )->groupBy('ym')->pluck('net');

        if ($rows->isEmpty()) {
            // Sin historial completo: usa el mes en curso como referencia.
            return $this->month($h, now())['free'];
        }

        return (float) $rows->avg();
    }

    /** Estado de una meta y proyección según el ahorro real. */
    public function goalStatus(Goal $goal, float $surplus): array
    {
        $saved = $goal->savedAmount();
        $target = (float) $goal->target_amount;
        $remaining = max(0, $target - $saved);
        $pct = $target > 0 ? min(100, round($saved / $target * 100)) : 0;

        $monthsToGo = $surplus > 0 ? (int) ceil($remaining / $surplus) : null;
        $eta = $monthsToGo !== null ? now()->addMonths($monthsToGo) : null;

        $required = null;
        $status = 'sin_fecha';

        if ($remaining <= 0) {
            $status = 'completada';
        } elseif ($goal->deadline) {
            $monthsLeft = max(1, (int) ceil(now()->floatDiffInMonths($goal->deadline, false)));
            $required = $remaining / $monthsLeft;
            $status = $goal->deadline->isPast() ? 'vencida'
                : ($surplus >= $required ? 'a_tiempo' : 'atrasada');
        } elseif ($surplus <= 0) {
            $status = 'sin_ahorro';
        }

        return compact('saved', 'target', 'remaining', 'pct', 'monthsToGo', 'eta', 'required', 'status');
    }

    private function monthExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', date)"
            : "DATE_FORMAT(date, '%Y-%m')";
    }
}
