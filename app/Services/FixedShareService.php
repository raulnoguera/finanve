<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\FixedExpense;
use App\Models\Household;

/**
 * Para gastos fijos compartidos: crea con anticipación la parte de la persona en cada cuota próxima,
 * así ve su calendario aunque el titular aún no haya registrado el pago de esa cuota.
 */
class FixedShareService
{
    public function sync(Household $h, ?FixedExpense $only = null, int $days = 45): int
    {
        $created = 0;

        $items = $h->fixedExpenses()->when($only, fn ($q) => $q->whereKey($only->id))->get();

        foreach ($items as $f) {
            $parts = $f->active ? collect($f->participants())->keyBy('person_id') : collect();
            $people = $h->people()->whereIn('id', $parts->keys())->get()->keyBy('id');
            $parts = $parts->filter(fn ($p, $id) => $people->has($id));

            // Partes programadas que ya no corresponden (pausado, sin compartir o con otras personas)
            Debt::where('fixed_expense_id', $f->id)->whereNull('transaction_id')->with('payments')->get()
                ->filter(fn (Debt $d) => $d->paid() <= 0 && ! $parts->has($d->person_id))
                ->each->delete();

            if ($parts->isEmpty()) {
                continue;
            }

            $kind = $f->share_mode === 'reimburse' ? 'lent' : 'shared';

            // Actualiza el monto de las programadas sin pagos
            Debt::where('fixed_expense_id', $f->id)->whereNull('transaction_id')->with('payments')->get()
                ->filter(fn (Debt $d) => $d->paid() <= 0 && $parts->has($d->person_id))
                ->each(fn (Debt $d) => $d->update([
                    'amount' => round($this->cuota($f, $d->fixed_due) * $parts[$d->person_id]['percent'] / 100, 2),
                    'percent' => $parts[$d->person_id]['percent'], 'kind' => $kind,
                ]));

            // Siempre se programa al menos la próxima cuota, aunque quede más allá de la ventana
            $occs = $f->occurrencesBetween(today(), today()->addDays($days));
            if ($occs->isEmpty()) {
                $occs = $f->occurrencesBetween(today(), today()->addMonths(14))->take(1);
            }

            foreach ($occs as $occ) {
                $due = $occ['due']->toDateString();
                $paidTx = $h->transactions()->where('fixed_expense_id', $f->id)->whereDate('fixed_due', $due)->exists();

                foreach ($parts as $personId => $part) {
                    $exists = $paidTx || Debt::where('fixed_expense_id', $f->id)->whereDate('fixed_due', $due)->where('person_id', $personId)->exists();
                    if ($exists) {
                        continue;
                    }

                    $h->debts()->create([
                        'user_id' => $f->user_id, 'fixed_expense_id' => $f->id, 'fixed_due' => $due,
                        'person_id' => $personId, 'kind' => $kind, 'person' => $people[$personId]->name,
                        'amount' => round($f->cuotaAmount($occ['n'] ?? null, $occ['of'] ?? null) * $part['percent'] / 100, 2), 'percent' => $part['percent'], 'due_date' => $due,
                        'note' => 'Gasto compartido: '.$f->name,
                    ]);
                    $created++;
                }
            }
        }

        return $created;
    }

    /** Valor de la cuota que vence en $due (la última absorbe el redondeo cuando hay "monto total"). */
    private function cuota(FixedExpense $f, $due): float
    {
        $d = \Illuminate\Support\Carbon::parse($due)->startOfDay();
        $occ = $f->occurrencesBetween($d, $d)->first();

        return $f->cuotaAmount($occ['n'] ?? null, $occ['of'] ?? null);
    }

    /**
     * Avisa por correo a cada persona con cuenta que se le asignó parte de un gasto fijo (aunque la fecha sea futura).
     * $before = participantes anteriores (para no repetir el aviso si su parte no cambió).
     */
    public function notifyAssigned(Household $h, FixedExpense $f, array $before = []): void
    {
        if (! $f->active) {
            return;
        }

        $prev = collect($before)->keyBy('person_id');
        $first = $f->occurrencesBetween(today(), today()->addMonths(14))->first()['due'] ?? null;
        $first = $first?->toDateString() ?? $f->start_date?->toDateString();

        foreach ($f->participants() as $part) {
            $old = $prev->get($part['person_id']);
            if ($old && abs($old['percent'] - $part['percent']) < 0.001) {
                continue;
            }

            $account = $h->people()->find($part['person_id'])?->account;
            if (! $account || ! $account->notify_email) {
                continue;
            }

            app(Notifier::class)->safely(fn () => $account->notify(
                new \App\Notifications\FixedShareNotification($f, $f->user?->name ?? 'Alguien', $h->currency(), $first, $part['percent'])
            ));
        }
    }
}
