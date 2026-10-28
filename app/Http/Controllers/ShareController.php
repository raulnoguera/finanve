<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShareController extends Controller
{
    /**
     * Define con quién se comparte un gasto y qué % paga cada persona.
     * shares[person_id] = porcentaje. Lo que no se asigna queda a cargo de quien registró el gasto.
     */
    public function store(Request $r, Transaction $transaction)
    {
        $this->own($r, $transaction);
        abort_unless($transaction->type === 'expense', 422, 'Solo se pueden compartir gastos.');
        $h = $this->household($r);

        $data = $r->validate([
            'shares' => ['nullable', 'array', 'max:20'],
            'shares.*' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'due_date' => ['nullable', 'date'],
            'mode' => ['nullable', 'in:split,reimburse'],
        ]);

        $input = collect($data['shares'] ?? [])->filter(fn ($p) => $p !== null && $p !== '')->map(fn ($p) => round((float) $p, 2));
        $people = $h->people()->with('account')->whereIn('id', $input->keys())->get()->keyBy('id');

        if ($input->keys()->diff($people->keys())->isNotEmpty()) {
            return back()->withErrors(['shares' => 'Hay una persona que no está en tu lista.']);
        }
        if ($input->sum() > 100.0001) {
            return back()->withErrors(['shares' => 'Los porcentajes suman más de 100 %. Ajusta los valores.']);
        }

        // split = cada quien paga su parte (tu gasto es solo tu %) | reimburse = pagas el total y te reembolsan
        $mode = $data['mode'] ?? 'split';
        $kind = $mode === 'split' ? 'shared' : 'lent';
        $base = (float) ($transaction->total_amount ?? $transaction->amount);   // total de la factura
        $notifyNew = [];

        DB::transaction(function () use ($transaction, $input, $people, $r, $data, $h, &$notifyNew, $mode, $kind, $base) {
            $existing = $transaction->shares()->with('payments')->get()->keyBy('person_id');

            foreach ($input as $personId => $percent) {
                $amount = round($base * $percent / 100, 2);
                $debt = $existing->get($personId);

                if ($debt) {
                    // Si ya pagó más de lo nuevo, la deuda queda saldada en lo pagado.
                    $amount = max($amount, $debt->paid());
                    $debt->update([
                        'percent' => $percent, 'amount' => $amount, 'kind' => $kind,
                        'due_date' => $data['due_date'] ?? $debt->due_date,
                        'settled_at' => $debt->paid() + 0.004 >= $amount ? ($debt->settled_at ?? now()) : null,
                    ]);
                } else {
                    $new = $h->debts()->create([
                        'user_id' => $r->user()->id, 'transaction_id' => $transaction->id, 'person_id' => $personId,
                        'kind' => $kind, 'person' => $people[$personId]->name, 'amount' => $amount, 'percent' => $percent,
                        'due_date' => $data['due_date'] ?? null,
                        'note' => 'Gasto compartido: '.($transaction->note ?: ($transaction->category?->fullName() ?? 'Gasto')),
                    ]);
                    $notifyNew[] = $new;
                }
            }

            // Personas quitadas: se elimina la parte; si ya había pagos, se conserva saldada por lo pagado.
            foreach ($existing->diffKeys($input->all()) as $debt) {
                if ($debt->paid() > 0) {
                    $debt->update(['amount' => $debt->paid(), 'settled_at' => $debt->settled_at ?? now()]);
                } else {
                    $debt->delete();
                }
            }

            // Tu gasto real: solo tu parte si se reparte; el total si pagas todo o si ya no se comparte
            if ($input->isNotEmpty() && $mode === 'split') {
                $transaction->update(['total_amount' => $base, 'amount' => round($base * (100 - $input->sum()) / 100, 2)]);
            } else {
                $transaction->update(['total_amount' => null, 'amount' => $base]);
            }
        });

        // Aviso por correo a quienes tienen cuenta vinculada y acaban de recibir una parte
        foreach ($notifyNew as $debt) {
            $acct = $people[$debt->person_id]->account;
            if ($acct && $acct->notify_email) {
                app(\App\Services\Notifier::class)->safely(fn () => $acct->notify(new \App\Notifications\ShareReceivedNotification($debt, $h->currency())));
            }
        }

        return back()->with('ok', $input->isEmpty() ? 'El gasto ya no está compartido.' : ($mode === 'split'
            ? 'Gasto compartido: tu parte es '.money($transaction->amount).'. Verás cuándo paga cada persona la suya.'
            : 'Gasto compartido: pagas el total y te reembolsan. Puedes ver quién ha pagado en Deudas.'));
    }
}
