<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Services\Notifier;
use App\Support\Money;
use Illuminate\Http\Request;

class SharedController extends Controller
{
    /** Gastos que otras familias comparten con la cuenta del usuario. Nada más de esas familias es visible. */
    private function mine(Request $r)
    {
        $personIds = $r->user()->linkedPeople()->pluck('id');

        // lent/shared: lo que debo pagar | owe: lo que me deben a mí (yo soy el acreedor)
        return Debt::whereIn('person_id', $personIds)->whereIn('kind', ['lent', 'shared', 'owe']);
    }

    public function index(Request $r)
    {
        $debts = $this->mine($r)->with(['payments.reporter', 'transaction.category.parent', 'user', 'linkedPerson.household', 'fixedExpense'])
            ->orderByRaw('settled_at is null desc')->orderBy('due_date')->latest('id')->get();

        $toPay = $debts->where('kind', '!=', 'owe');
        $toCollect = $debts->where('kind', 'owe');

        $collect = $toCollect->whereNull('settled_at')->groupBy(fn ($d) => $d->linkedPerson->household->currency())
            ->map(fn ($g) => $g->sum(fn ($d) => $d->remaining()))
            ->filter(fn ($v) => $v > 0.004)
            ->map(fn ($v, $cur) => Money::format($v, $cur))->values();

        $pending = $toPay->whereNull('settled_at')->groupBy(fn ($d) => $d->linkedPerson->household->currency())
            ->map(fn ($g) => $g->sum(fn ($d) => $d->remaining()))
            ->filter(fn ($v) => $v > 0.004)
            ->map(fn ($v, $cur) => Money::format($v, $cur))->values();

        // Gastos fijos compartidos contigo: serie, tu % y próximas cuotas
        $series = $toPay->whereNotNull('fixed_expense_id')->groupBy('fixed_expense_id')->map(function ($g) {
            $f = $g->first()->fixedExpense;
            $next = $g->whereNull('settled_at')->sortBy('due_date')->first();

            $cur = $g->first()->linkedPerson->household->currency();
            $byDue = $g->keyBy(fn ($d) => $d->fixed_due?->toDateString());
            $pctS = (float) $g->first()->percent;
            $cuotas = $f ? $f->schedule()->map(function ($o) use ($byDue, $f, $pctS) {
                $debt = $byDue->get($o['due']->toDateString());

                return [
                    'n' => $o['n'], 'of' => $o['of'], 'due' => $o['due'],
                    'amount' => $debt?->amount ?? round($f->cuotaAmount($o['n'], $o['of']) * $pctS / 100, 2),
                    'state' => ! $debt ? 'scheduled' : ($debt->settled_at ? 'paid' : ($debt->paid() > 0 ? 'partial' : 'pending')),
                ];
            })->values() : collect();

            return [
                'cuotas' => $cuotas, 'ends' => $cuotas->last()['due'] ?? null,
                'name' => $f?->name ?? preg_replace('/^Gasto compartido: /', '', (string) $g->first()->note),
                'label' => $f ? trim($f->frequencyLabel().' '.($f->termLabel() ?? '')) : null,
                'percent' => $g->first()->percent, 'per' => $g->first()->amount,
                'cur' => $g->first()->linkedPerson->household->currency(),
                'owner' => $g->first()->user?->name,
                'next' => $next?->due_date, 'open' => $g->whereNull('settled_at')->count(),
            ];
        })->values();

        // Historial: partes ya saldadas y línea de tiempo de pagos (reportados por ti o confirmados)
        $timeline = $debts->flatMap(fn ($d) => $d->payments->map(fn ($p) => ['debt' => $d, 'pay' => $p]))
            ->sortByDesc(fn ($x) => $x['pay']->date->toDateString().'|'.str_pad((string) $x['pay']->id, 10, '0', STR_PAD_LEFT))->values()->take(40);

        return view('shared.index', [
            'debts' => $debts, 'active' => $debts->whereNull('settled_at')->values(), 'history' => $debts->whereNotNull('settled_at')->values(),
            'timeline' => $timeline, 'pending' => $pending, 'collect' => $collect, 'series' => $series,
        ]);
    }

    public function pay(Request $r, int $debt, Notifier $notifier)
    {
        $d = $this->mine($r)->with('linkedPerson.household', 'user')->findOrFail($debt);

        $data = $r->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'note' => ['nullable', 'string', 'max:140'],
        ]);

        $left = $d->remaining();
        if ($left <= 0.004) {
            return back()->with('ok', 'Esta parte ya está saldada.');
        }

        $amount = min((float) $data['amount'], $left);
        $payment = $d->payments()->create([
            'amount' => $amount, 'date' => today(), 'reported_by' => $r->user()->id,
            'note' => $data['note'] ?? ($d->kind === 'owe' ? 'Cobro confirmado' : null),
        ]);

        if ($d->fresh('payments')->remaining() <= 0.004) {
            $d->update(['settled_at' => now()]);
        }

        // Aviso a quien registró el gasto
        $owner = $d->user;
        if ($owner && $owner->notify_email) {
            $cur = $d->linkedPerson->household->currency();
            $notifier->safely(fn () => $owner->notify(new \App\Notifications\SharedPaymentReportedNotification($payment, $cur)));
        }

        return back()->with('ok', 'Pago registrado. '.($d->fresh('payments')->remaining() <= 0.004 ? '¡Tu parte quedó saldada!' : 'Gracias por avisar.'));
    }
}
