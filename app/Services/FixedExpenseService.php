<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Household;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FixedExpenseService
{
    /**
     * Gastos fijos "precargados" de un mes con su estado: paid | pending | overdue.
     * Un gasto puede tener varias cuotas en el mes (p. ej. cada 21 días) o ninguna (trimestral).
     * Cada fila: fixed, due (vencimiento), key (Y-m-d), n (nº de cuota), tx (pago registrado o null), status.
     */
    public function month(Household $h, Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $txs = $h->transactions()->whereNotNull('fixed_expense_id')
            ->where(fn ($q) => $q->whereDate('fixed_due', '>=', $start->toDateString())->whereDate('fixed_due', '<=', $end->toDateString())
                ->orWhere(fn ($q2) => $q2->whereNull('fixed_due')->where('fixed_month', $start->format('Y-m'))))
            ->get();
        $byDue = $txs->whereNotNull('fixed_due')->keyBy(fn ($t) => $t->fixed_expense_id.'|'.$t->fixed_due->toDateString());
        $legacy = $txs->whereNull('fixed_due')->keyBy('fixed_expense_id');
        $paidIds = $txs->pluck('fixed_expense_id')->unique();

        // Partes de otras personas por cuota (para saber si la cuota está completa)
        $shareRows = Debt::where('household_id', $h->id)->whereNotNull('fixed_expense_id')
            ->whereDate('fixed_due', '>=', $start->toDateString())->whereDate('fixed_due', '<=', $end->toDateString())
            ->with('payments')->get()->groupBy(fn ($d) => $d->fixed_expense_id.'|'.$d->fixed_due->toDateString());

        $people = $h->people()->get()->keyBy('id');
        $rows = collect();
        // Incluye pausados y eliminados: un cambio a futuro no reescribe los meses anteriores
        $items = $h->fixedExpenses()->withTrashed()->with('category.parent', 'person', 'sharePerson')
            ->orderBy('due_day')->orderBy('name')->get();

        foreach ($items as $f) {
            // Un mensual nuevo no genera deudas de meses anteriores a su creación.
            if ($f->isMonthly() && $f->created_at->gt($end)) {
                continue;
            }
            // Pausado o eliminado: solo cuenta lo anterior a esa fecha
            $endedOn = $f->ended_at ?? ($f->trashed() ? $f->deleted_at : null) ?? (! $f->active ? $f->updated_at : null);

            foreach ($f->occurrencesBetween($start, $end) as $occ) {
                $due = $occ['due'];
                $tx = $byDue->get($f->id.'|'.$due->toDateString()) ?? ($f->isMonthly() ? $legacy->get($f->id) : null);

                if ($endedOn && ! $tx && $due->gte($endedOn->copy()->startOfDay())) {
                    continue;
                }
                // Eliminado: solo se conserva lo que ya se pagó (no deja cuotas "vencidas" fantasma)
                if ($f->trashed() && ! $tx) {
                    continue;
                }

                // Quiénes de los demás aún no pagan su parte de esta cuota
                $dueRows = collect($shareRows->get($f->id.'|'.$due->toDateString(), []));
                $waiting = $dueRows->filter(fn ($d) => $d->remaining() > 0.004)->pluck('person')->values();
                // Si hay reparto configurado pero la parte de alguien aún no existe, también falta esa persona
                if ($tx) {
                    foreach ($f->participants() as $part) {
                        if (! $dueRows->contains('person_id', $part['person_id'])) {
                            $waiting->push($people->get($part['person_id'])?->name ?? 'otra persona');
                        }
                    }
                }

                $rows->push([
                    'fixed' => $f, 'due' => $due, 'key' => $due->toDateString(), 'n' => $occ['n'], 'of' => $occ['of'], 'tx' => $tx,
                    // paid = tu parte está pagada; complete = además todos los demás pagaron la suya
                    'status' => $tx ? 'paid' : ($due->lt(today()) ? 'overdue' : 'pending'),
                    'waiting' => $tx ? $waiting : collect(),
                    'complete' => $tx && $waiting->isEmpty(),
                    'my' => $f->myAmount(),
                ]);
            }
        }

        return $rows->sortBy(fn ($r) => $r['due']->toDateString().'|'.$r['fixed']->name)->values();
    }

    /** Totales de un mes: esperado, pagado y pendiente (en moneda base). */
    public function totals(Collection $rows): array
    {
        // Todo en términos de TU parte (lo que realmente te toca pagar)
        $expected = $rows->sum(fn ($r) => $r['tx'] ? (float) $r['tx']->amount : (float) $r['my']);
        $paid = $rows->where('status', 'paid')->sum(fn ($r) => (float) $r['tx']->amount);
        $pending = $rows->where('status', '!=', 'paid')->sum(fn ($r) => (float) $r['my']);

        return [
            'expected' => $expected, 'paid' => $paid, 'pending' => $pending,
            'count' => $rows->count(),
            'paid_count' => $rows->where('status', 'paid')->count(),
            'unpaid_count' => $rows->where('status', '!=', 'paid')->count(),
            'overdue_count' => $rows->where('status', 'overdue')->count(),
            'awaiting_count' => $rows->filter(fn ($r) => $r['tx'] && $r['waiting']->isNotEmpty())->count(),
        ];
    }
}
