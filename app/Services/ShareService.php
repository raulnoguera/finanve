<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Household;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;

class ShareService
{
    /** Crea la parte que una persona debe de un gasto (seguimiento como "Me deben") y le avisa si tiene cuenta. */
    public function create(Household $h, User $by, Transaction $tx, Person $person, float $percent, ?string $due = null, ?int $fixedId = null, ?string $fixedDue = null, string $kind = 'lent', ?float $base = null): Debt
    {
        $debt = $h->debts()->create([
            'user_id' => $by->id, 'transaction_id' => $tx->id, 'person_id' => $person->id,
            'kind' => $kind, 'person' => $person->name, 'percent' => $percent,
            'amount' => round(($base ?? (float) ($tx->total_amount ?? $tx->amount)) * $percent / 100, 2),
            'due_date' => $due,
            'fixed_expense_id' => $fixedId, 'fixed_due' => $fixedDue,
            'note' => 'Gasto compartido: '.($tx->note ?: ($tx->category?->fullName() ?? 'Gasto')),
        ]);

        $account = $person->account;
        if ($account && $account->notify_email) {
            app(Notifier::class)->safely(fn () => $account->notify(new \App\Notifications\ShareReceivedNotification($debt, $h->currency())));
        }

        return $debt;
    }
}
