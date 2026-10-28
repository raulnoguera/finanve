<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $r)
    {
        $h = $this->household($r);
        $month = $this->month($r);
        $type = in_array($r->query('tipo'), ['income', 'expense', 'saving']) ? $r->query('tipo') : null;

        $transactions = $h->transactions()->with('category.parent', 'user', 'person', 'goal', 'shares.payments')
            ->whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($r->query('categoria'), fn ($q, $c) => $q->where('category_id', $c))
            ->when($place = trim((string) $r->query('lugar')), fn ($q) => $q->where('place', $place))
            ->when($personId = (int) $r->query('persona'), fn ($q) => $q->where('person_id', $personId))
            ->latest('date')->latest('id')->get();

        $pending = $h->transactions()->withoutGlobalScope('paid')->where('status', 'pending')->with('category.parent', 'person')->latest('date')->latest('id')->get();

        $categories = $h->categories()->orderBy('name')->get();

        // Resumen del mes por lugar o persona (sin filtrar por lugar, para poder cambiar de uno a otro)
        $places = $h->transactions()->whereNotNull('place')
            ->whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->selectRaw('place, SUM(amount) as total, COUNT(*) as n')->groupBy('place')->orderByDesc('total')->take(12)->get();

        $people = $h->people()->orderBy('name')->get();
        $personTotals = $h->transactions()->whereNotNull('person_id')->where('type', 'expense')
            ->whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->selectRaw('person_id, SUM(amount) as total')->groupBy('person_id')->pluck('total', 'person_id');
        $personId = $personId ?: null;

        return view('transactions.index', compact('transactions', 'pending', 'categories', 'month', 'type', 'place', 'places', 'people', 'personTotals', 'personId'));
    }

    public function store(Request $r)
    {
        $h = $this->household($r);
        $this->resolveMe($r);

        $data = $r->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'currency' => ['required', Rule::in(currency_codes())],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('household_id', $h->id)],
            'date' => ['required', 'date', 'before_or_equal:'.($r->input('type') === 'expense' ? now()->addYears(2)->toDateString() : 'today')],
            'note' => ['nullable', 'string', 'max:140'],
            'new_category' => ['nullable', 'string', 'max:40'],
            'place' => ['nullable', 'string', 'max:60'],
            'person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $h->id)],
            'new_person' => ['nullable', 'string', 'max:60'],
            'new_person_email' => ['nullable', 'email', 'max:120'],
            'new_person_invite' => ['nullable', 'boolean'],
            'share_person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $h->id)],
            'share_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'shares' => ['nullable', 'array', 'max:10'],
            'shares.*.person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $h->id)],
            'shares.*.percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'share_mode' => ['nullable', 'in:split,reimburse'],
            'status' => ['nullable', 'in:paid,pending'],
        ]);

        // Persona nueva creada al registrar (se reutiliza si ya existe con ese nombre)
        $inviteMsg = null;
        if ($newP = trim(preg_replace('/\s+/u', ' ', (string) ($data['new_person'] ?? '')))) {
            $newEmail = ($e = trim((string) ($data['new_person_email'] ?? ''))) === '' ? null : mb_strtolower($e);
            $person = $h->people()->whereRaw('LOWER(name) = ?', [mb_strtolower($newP)])->first()
                ?? $h->people()->create(['name' => $newP, 'email' => $newEmail, 'color' => \App\Models\Person::colorFor($newP)]);
            if ($newEmail && ! $person->email) {
                $person->update(['email' => $newEmail]);
            }
            $data['person_id'] = $person->id;

            // Con correo y "invitarle" marcado: se le invita a ver lo que se le comparta
            if ($newEmail && $r->boolean('new_person_invite', true)) {
                [, $inviteMsg] = app(\App\Services\PersonInviter::class)->issue($r->user(), $person, $newEmail);
            }
        }

        // Categoría nueva creada al registrar el movimiento (se reutiliza si ya existía con ese nombre)
        if ($new = trim((string) ($data['new_category'] ?? ''))) {
            $palette = ['#10b981', '#14b8a6', '#06b6d4', '#0ea5e9', '#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#84cc16', '#f97316'];
            $cat = $h->categories()->where('type', $data['type'])->whereNull('parent_id')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($new)])->first()
                ?? $h->categories()->create([
                    'name' => $new, 'type' => $data['type'], 'icon' => 'tag',
                    'color' => $palette[crc32(mb_strtolower($new)) % count($palette)],
                ]);
            $data['category_id'] = $cat->id;
        }

        [$usd, $rate] = $this->toUsd((float) $data['amount'], $data['currency']);

        $tx = $h->transactions()->create([
            'user_id' => $r->user()->id,
            'category_id' => $data['category_id'] ?? null,
            'person_id' => $data['type'] === 'expense' ? ($data['person_id'] ?? null) : null,
            'type' => $data['type'],
            // Un gasto con fecha futura siempre queda por pagar: cuenta en el mes que le corresponde
            'status' => $data['type'] === 'expense' && (($data['status'] ?? 'paid') === 'pending' || \Illuminate\Support\Carbon::parse($data['date'])->isFuture()) ? 'pending' : 'paid',
            'amount' => $usd,
            'currency' => $data['currency'],
            'original_amount' => $data['amount'],
            'rate' => $rate,
            'date' => $data['date'],
            'note' => $data['note'] ?? null,
            'place' => $this->canonicalPlace($r, $data['place'] ?? null),
        ]);

        // Compartir con una o varias personas desde el registro
        $parts = $tx->type === 'expense' ? \App\Support\ShareInput::parse($data) : [];
        if ($parts) {
            $split = ($data['share_mode'] ?? 'split') === 'split';
            $total = (float) $tx->amount;

            if ($split) {
                $others = array_sum(array_column($parts, 'percent'));
                $tx->update(['total_amount' => $total, 'amount' => round($total * (100 - $others) / 100, 2)]);
            }
            foreach ($parts as $part) {
                $sharer = $h->people()->with('account')->find($part['person_id']);
                app(\App\Services\ShareService::class)->create($h, $r->user(), $tx, $sharer, $part['percent'], null, null, null, $split ? 'shared' : 'lent', $total);
            }
        }

        if ($tx->type === 'expense' && ! $tx->isPending()) {
            app(\App\Services\Notifier::class)->budgetCheck($h, $tx->category_id, $tx->date);
        }

        $done = $data['type'] === 'income' ? 'Ingreso registrado.' : ($tx->isPending() ? ($tx->date->isFuture() ? 'Gasto programado para el '.$tx->date->translatedFormat('d \\d\\e F').': se descontará en ese mes.' : 'Gasto registrado como por pagar: confirma el pago cuando lo hagas.') : 'Gasto registrado.');

        return back()->with('ok', $inviteMsg ? $done.' '.$inviteMsg : $done);
    }

    public function update(Request $r, Transaction $transaction)
    {
        $this->own($r, $transaction);
        $h = $this->household($r);
        $this->resolveMe($r);

        $data = $r->validate([
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('household_id', $h->id)->where('type', $transaction->type)],
            'place' => ['nullable', 'string', 'max:60'],
            'person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $h->id)],
        ]);

        if ($r->has('person_id') && ! $r->has('category_id')) {
            $transaction->update(['person_id' => $transaction->type === 'expense' ? ($data['person_id'] ?? null) : null]);

            return back()->with('ok', 'Actualizado.');
        }

        if ($r->has('place')) {
            $transaction->update(['place' => $this->canonicalPlace($r, $data['place'] ?? null)]);

            return back()->with('ok', 'Actualizado.');
        }

        $transaction->update(['category_id' => $data['category_id'] ?? null]);

        if ($transaction->type === 'expense') {
            app(\App\Services\Notifier::class)->budgetCheck($h, $transaction->category_id, $transaction->date);
        }

        return back()->with('ok', 'Categoría actualizada.');
    }

    /** Confirma que un gasto "por pagar" ya se pagó: desde ahora cuenta en saldos, presupuestos y reportes. */
    public function confirm(Request $r, Transaction $transaction)
    {
        $this->own($r, $transaction);
        abort_unless($transaction->isPending(), 422, 'Este gasto ya estaba pagado.');

        $data = $r->validate(['date' => ['nullable', 'date', 'before_or_equal:today']]);

        $transaction->update(['status' => 'paid', 'date' => $data['date'] ?? today()]);
        app(\App\Services\Notifier::class)->budgetCheck($this->household($r), $transaction->category_id, $transaction->date);

        return back()->with('ok', 'Pago confirmado ✓ Ya cuenta en tus gastos.');
    }

    public function destroy(Request $r, Transaction $transaction)
    {
        $this->own($r, $transaction);

        // Partes compartidas: sin pagos se eliminan; con pagos se conservan (ya no ligadas al gasto)
        foreach ($transaction->shares()->with('payments')->get() as $share) {
            $share->paid() > 0 ? $share->update(['transaction_id' => null]) : $share->delete();
        }

        $goal = $transaction->type === 'saving' ? $transaction->goal : null;
        $transaction->delete();

        if ($goal) {
            $done = $goal->savedAmount() >= (float) $goal->target_amount;
            $goal->update(['completed_at' => $done ? ($goal->completed_at ?? now()) : null]);
        }

        return back()->with('ok', $goal ? 'Aporte eliminado: vuelve a contar como disponible.' : 'Movimiento eliminado.');
    }
}
