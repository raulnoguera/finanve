<?php

namespace App\Console\Commands;

use App\Models\Debt;
use App\Models\Household;
use App\Notifications\DebtReminderNotification;
use App\Notifications\SharedDueReminderNotification;
use App\Services\Notifier;
use Illuminate\Console\Command;

class NotifyDebts extends Command
{
    protected $signature = 'notify:debts';
    protected $description = 'Avisa de deudas y préstamos que vencen hoy o en 3 días';

    public function handle(Notifier $notifier): int
    {
        $sent = 0;

        Household::query()->each(function (Household $h) use ($notifier, &$sent) {
            $due = $h->debts()->incurred()->whereIn('kind', ['owe', 'lent'])->whereNull('settled_at')
                ->whereBetween('due_date', [today(), today()->addDays(3)])->get()
                ->filter(fn (Debt $d) => $d->remaining() > 0)
                ->filter(function (Debt $d) use ($notifier) {
                    $stage = $d->due_date->isToday() ? 'today' : 'soon';
                    return $notifier->once("debt:{$d->id}:{$stage}:{$d->due_date->format('Ymd')}", 10);
                })->values();

            if ($due->isNotEmpty()) {
                $notifier->household($h, new DebtReminderNotification($due, $h->currency()));
                $sent++;
            }
        });

        // Quienes deben una parte y tienen cuenta propia: aviso 3 días antes, el día y 1 día después del vencimiento
        $mine = Debt::query()->whereNull('settled_at')->whereIn('kind', ['lent', 'shared'])->whereNotNull('person_id')
            ->whereBetween('due_date', [today()->subDay(), today()->addDays(3)])
            ->with(['payments', 'transaction', 'linkedPerson.household', 'linkedPerson.account'])->get()
            ->filter(fn (Debt $d) => $d->remaining() > 0 && $d->linkedPerson?->account)
            ->filter(function (Debt $d) use ($notifier) {
                $stage = $d->due_date->isToday() ? 'today' : ($d->due_date->isPast() ? 'late' : 'soon');
                return $notifier->once("sharedue:{$d->id}:{$stage}:{$d->due_date->format('Ymd')}", 10);
            });

        $people = 0;
        foreach ($mine->groupBy(fn (Debt $d) => $d->linkedPerson->user_id) as $debts) {
            $user = $debts->first()->linkedPerson->account;
            if ($user->notify_email) {
                $notifier->safely(fn () => $user->notify(new SharedDueReminderNotification($debts->values())));
                $people++;
            }
        }

        $this->info("Familias avisadas: {$sent} · personas con cuenta avisadas: {$people}");

        return self::SUCCESS;
    }
}
