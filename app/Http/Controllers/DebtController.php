<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DebtController extends Controller
{
    public function index(Request $r)
    {
        $all = $this->household($r)->debts()->with('payments.reporter', 'items.payment', 'transaction', 'linkedPerson.account')->orderByRaw('settled_at is null desc')->orderBy('due_date')->get();

        // Cuotas de gastos fijos compartidos aún no pagadas por el titular: se ven aparte, con su calendario
        $scheduled = $all->filter(fn ($d) => $d->fixed_expense_id && ! $d->transaction_id)->values();
        $debts = $all->reject(fn ($d) => $d->fixed_expense_id && ! $d->transaction_id)->values();

        $h = $this->household($r);

        // Historial: lo ya saldado (sale de las listas activas) y todos los pagos, del más reciente al más antiguo
        $history = $debts->whereNotNull('settled_at')->sortByDesc('settled_at')->values();
        $debts = $debts->whereNull('settled_at')->values();
        $timeline = $all->flatMap(fn ($d) => $d->payments->map(fn ($p) => ['debt' => $d, 'pay' => $p]))
            ->sortByDesc(fn ($x) => $x['pay']->date->toDateString().'|'.str_pad((string) $x['pay']->id, 10, '0', STR_PAD_LEFT))->values()->take(40);

        return view('debts.index', [
            'history' => $history, 'timeline' => $timeline,
            'people' => $h->people()->where(fn ($q) => $q->whereNull('member_user_id')->orWhere('member_user_id', '!=', $r->user()->id))
                ->orderBy('name')->get(),
            'owe' => $debts->where('kind', 'owe')->values(),
            'lent' => $debts->where('kind', 'lent')->values(),
            'shared' => $debts->where('kind', 'shared')->values(),
            'scheduled' => $scheduled,
        ]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'kind' => ['required', 'in:owe,lent'],
            'person' => ['required_without_all:person_id,counterparty_email', 'nullable', 'string', 'max:60'],
            'person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $this->household($r)->id)],
            'counterparty_email' => ['nullable', 'email', 'max:120'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
            'currency' => ['required', Rule::in(currency_codes())],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:140'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.name' => ['nullable', 'string', 'max:80'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
        ]);

        // Detalles: solo las filas con monto; cada uno se convierte a la moneda base
        $items = collect($data['items'] ?? [])
            ->filter(fn ($i) => isset($i['amount']) && (float) $i['amount'] > 0)
            ->map(fn ($i) => [
                'name' => trim((string) ($i['name'] ?? '')) ?: 'Detalle',
                'amount' => $this->toUsd((float) $i['amount'], $data['currency'])[0],
            ])->values();
        $sum = round((float) $items->sum('amount'), 2);

        if (empty($data['amount']) && $items->isEmpty()) {
            return back()->withErrors(['amount' => 'Indica el monto de la deuda o agrega sus detalles.'])->withInput();
        }

        // El total manda; si no se escribe, es la suma de los detalles
        $total = ! empty($data['amount']) ? $this->toUsd((float) $data['amount'], $data['currency'])[0] : $sum;

        if ($sum > $total + 0.01) {
            return back()->withErrors(['amount' => 'Los detalles suman '.money($sum).', más que el total ('.money($total).'). Ajusta los montos.'])->withInput();
        }

        // Contraparte: una persona de tu lista o alguien con cuenta en Finanve (por su correo)
        $h = $this->household($r);
        $counterparty = ! empty($data['person_id']) ? $h->people()->find($data['person_id']) : null;
        $email = ! empty($data['counterparty_email']) ? mb_strtolower(trim($data['counterparty_email'])) : null;

        if (! $counterparty && $email) {
            $counterparty = $h->people()->whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $counterparty) {
                $account = \App\Models\User::whereRaw('LOWER(email) = ?', [$email])->first();
                // El nombre de la persona es el de su cuenta (o la parte local de su correo), no el título de la deuda
                $base = $account?->name ?? ucfirst(strstr($email, '@', true));
                $name = $base;
                for ($n = 2; $h->people()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists(); $n++) {
                    $name = "{$base} {$n}";
                }
                $counterparty = $h->people()->create(['name' => $name, 'email' => $email, 'relation' => 'otro', 'color' => \App\Models\Person::colorFor($name)]);
            }
        }

        $title = trim((string) ($data['person'] ?? '')) ?: ($counterparty?->name ?? 'Deuda');

        $debt = $h->debts()->create([
            'user_id' => $r->user()->id, 'kind' => $data['kind'], 'person' => $title, 'person_id' => $counterparty?->id,
            'amount' => $total, 'due_date' => $data['due_date'] ?? null, 'note' => $data['note'] ?? null,
        ]);

        foreach ($items as $i => $it) {
            $debt->items()->create($it + ['position' => $i]);
        }

        $msg = $items->isEmpty() ? 'Registrado.' : 'Registrado con '.$items->count().' '.($items->count() === 1 ? 'detalle' : 'detalles').'.';

        // Si la persona tiene correo y aún no está vinculada a una cuenta: se le invita (o le llega la solicitud)
        if ($counterparty && ! $counterparty->user_id && ! $counterparty->member_user_id) {
            $to = $email ?: $counterparty->email;
            $hasPending = $h->invitations()->where('person_id', $counterparty->id)->whereNull('accepted_at')->whereNull('declined_at')->where('expires_at', '>', now())->exists();

            if ($to && ! $hasPending) {
                if ($email && ! $counterparty->email) {
                    $counterparty->update(['email' => $email]);
                }
                [$type, $inviteMsg] = app(\App\Services\PersonInviter::class)->issue($r->user(), $counterparty, $to);
                $msg .= ' '.$inviteMsg;
            }
        }

        return back()->with('ok', $msg);
    }

    /** Edita nombre, total, fecha y nota (el total no puede ser menor a lo detallado ni a lo ya pagado). */
    public function update(Request $r, Debt $debt)
    {
        $this->own($r, $debt);
        $data = $r->validate([
            'person' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:140'],
        ]);

        $floor = max($debt->detailedTotal(), $debt->paid());
        if ((float) $data['amount'] + 0.004 < $floor) {
            return back()->withErrors(['amount' => 'El total no puede ser menor a '.money($floor).' (lo detallado o ya pagado).']);
        }

        $debt->update(['person' => $data['person'], 'amount' => $data['amount'], 'due_date' => $data['due_date'] ?? null, 'note' => $data['note'] ?? null]);
        $debt->unsetRelation('payments');
        $debt->update(['settled_at' => $debt->remaining() <= 0.004 ? ($debt->settled_at ?? now()) : null]);

        return back()->with('ok', 'Deuda actualizada.');
    }

    public function itemStore(Request $r, Debt $debt)
    {
        $this->own($r, $debt);
        $data = $r->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'currency' => ['required', Rule::in(currency_codes())],
        ]);

        [$usd] = $this->toUsd((float) $data['amount'], $data['currency']);
        $would = $debt->detailedTotal() + $usd;

        if ($would > (float) $debt->amount + 0.01) {
            return back()->withErrors(['amount' => 'Los detalles sumarían '.money($would).' y la deuda es de '.money($debt->amount).'. Sube el total de la deuda (Editar) o reduce el monto.']);
        }

        $debt->items()->create([
            'name' => trim((string) ($data['name'] ?? '')) ?: 'Detalle',
            'amount' => $usd,
            'position' => (int) $debt->items()->max('position') + 1,
        ]);

        return back()->with('ok', 'Detalle agregado.');
    }

    public function itemDestroy(Request $r, Debt $debt, \App\Models\DebtItem $item)
    {
        $this->own($r, $debt);
        abort_unless($item->debt_id === $debt->id, 404);

        $item->delete();   // los pagos hechos se conservan (quedan sin detalle)

        return back()->with('ok', 'Detalle eliminado.');
    }

    /** Marca el detalle como pagado (registra el pago por su monto) o deshace ese pago. */
    public function itemPay(Request $r, Debt $debt, \App\Models\DebtItem $item)
    {
        $this->own($r, $debt);
        abort_unless($item->debt_id === $debt->id, 404);

        $payment = $item->payment;

        if ($payment) {
            $payment->delete();
            $msg = 'Pago del detalle deshecho.';
        } else {
            $amount = min((float) $item->amount, $debt->remaining());
            if ($amount <= 0.004) {
                return back()->with('ok', 'La deuda ya está saldada.');
            }
            $debt->payments()->create(['amount' => $amount, 'date' => today(), 'debt_item_id' => $item->id, 'note' => $item->name]);
            $msg = '«'.$item->name.'» pagado: '.money($amount).'.';
        }

        $debt->unsetRelation('payments');
        $debt->update(['settled_at' => $debt->remaining() <= 0.004 ? ($debt->settled_at ?? now()) : null]);

        return back()->with('ok', $msg);
    }

    public function pay(Request $r, Debt $debt)
    {
        $this->own($r, $debt);

        $data = $r->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'currency' => ['required', Rule::in(currency_codes())],
        ]);

        [$usd] = $this->toUsd((float) $data['amount'], $data['currency']);
        $usd = min($usd, $debt->remaining());

        $debt->payments()->create(['amount' => $usd, 'date' => now()]);

        if ($debt->remaining() <= 0.004) {
            $debt->update(['settled_at' => now()]);
        }

        return back()->with('ok', 'Pago registrado.');
    }

    public function destroy(Request $r, Debt $debt)
    {
        $this->own($r, $debt)->delete();

        return back()->with('ok', 'Eliminado.');
    }

    /** Deshace un pago (por ejemplo, uno reportado por error). */
    public function unpay(Request $r, Debt $debt, \App\Models\DebtPayment $payment)
    {
        $this->own($r, $debt);
        abort_unless($payment->debt_id === $debt->id, 404);

        $payment->delete();

        $debt->unsetRelation('payments');
        $debt->update(['settled_at' => $debt->remaining() <= 0.004 ? ($debt->settled_at ?? now()) : null]);

        return back()->with('ok', 'Pago deshecho.');
    }
}
