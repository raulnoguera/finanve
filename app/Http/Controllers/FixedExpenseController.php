<?php

namespace App\Http\Controllers;

use App\Models\FixedExpense;
use App\Services\FixedExpenseService;
use App\Models\FixedExpense as FixedModel;
use App\Services\FixedShareService;
use App\Services\Notifier;
use App\Services\ShareService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FixedExpenseController extends Controller
{
    public function index(Request $r, FixedExpenseService $svc)
    {
        $h = $this->household($r);
        $month = $this->month($r);
        $rows = $svc->month($h, $month);

        $cats = $h->categories()->where('type', 'expense')->orderBy('name')->get();

        // Calendario completo de cuotas de los gastos con fin (para ver cuándo termina cada uno)
        $paidDues = $h->transactions()->whereNotNull('fixed_due')->get(['fixed_expense_id', 'fixed_due'])
            ->groupBy('fixed_expense_id')->map(fn ($g) => $g->map(fn ($t) => $t->fixed_due->toDateString())->all());
        $calendars = $h->fixedExpenses()->where('active', true)->get()->filter->isFinite()->mapWithKeys(fn ($f) => [$f->id => $f->schedule()->map(fn ($o) => $o + [
            'paid' => in_array($o['due']->toDateString(), $paidDues->get($f->id, []), true),
            'amount' => $f->cuotaAmount($o['n'], $o['of']),
        ])->values()]);

        return view('fixed.index', [
            'calendars' => $calendars,
            'month' => $month,
            'rows' => $rows,
            'totals' => $svc->totals($rows),
            'cats' => $cats,
            'people' => $h->people()->orderByRaw('member_user_id = '.(int) $r->user()->id.' desc')->orderBy('name')->get(),
            'kids' => $cats->whereNotNull('parent_id')->groupBy('parent_id'),
            'inactive' => $h->fixedExpenses()->where('active', false)->orderBy('name')->get(),
            'suggested' => ['Alquiler o hipoteca', 'Condominio', 'Electricidad', 'Agua', 'Internet', 'Teléfono', 'Colegio', 'Seguro', 'Suscripciones', 'Cuota de préstamo'],
        ]);
    }

    private function rules(Request $r): array
    {
        $h = $this->household($r);

        return [
            'name' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'frequency' => ['nullable', Rule::in(array_keys(FixedModel::FREQUENCIES))],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'interval_days' => ['nullable', 'integer', 'between:1,365'],
            'start_date' => ['nullable', 'date'],
            'installments' => ['nullable', 'integer', 'between:1,120'],
            'amount_mode' => ['nullable', Rule::in(['per_cuota', 'total'])],
            'duration_months' => ['nullable', 'integer', 'between:1,60'],
            'repeat_term' => ['nullable', 'boolean'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('household_id', $h->id)->where('type', 'expense')],
            'person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $h->id)],
            'share_person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $h->id)],
            'share_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'shares' => ['nullable', 'array', 'max:10'],
            'shares.*.person_id' => ['nullable', Rule::exists('people', 'id')->where('household_id', $h->id)],
            'shares.*.percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'share_mode' => ['nullable', Rule::in(['split', 'reimburse'])],
        ];
    }

    /** Traduce el formulario (frecuencia + campos) a las columnas del gasto fijo, validando lo que cada opción necesita. */
    private function attributes(Request $r): array
    {
        $this->resolveMe($r);
        $data = $r->validate($this->rules($r));
        $freq = $data['frequency'] ?? 'monthly';
        [, $unit, $count] = FixedModel::FREQUENCIES[$freq];

        if ($freq === 'monthly') {
            if (empty($data['due_day'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['due_day' => 'Indica el día del mes en que vence.']);
            }
            $start = null;
            $dueDay = (int) $data['due_day'];
        } else {
            if (empty($data['start_date'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['start_date' => 'Indica la fecha del primer vencimiento.']);
            }
            if ($freq === 'days') {
                if (empty($data['interval_days'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['interval_days' => 'Indica cada cuántos días se paga.']);
                }
                $count = (int) $data['interval_days'];
            }
            $start = Carbon::parse($data['start_date']);
            $dueDay = $start->day;
        }

        $duration = $freq === 'monthly' ? null : ($data['duration_months'] ?? null);
        if ($r->boolean('repeat_term') && ! $duration) {
            throw \Illuminate\Validation\ValidationException::withMessages(['duration_months' => 'Para repetir el periodo indica cuántos meses dura.']);
        }

        // "Monto total": se divide entre las cuotas; `amount` queda como el valor de cada cuota
        $amount = (float) $data['amount'];
        $totalAmount = null;
        if (($data['amount_mode'] ?? 'per_cuota') === 'total' && $freq !== 'monthly') {
            if (empty($data['installments']) || (int) $data['installments'] < 2) {
                throw \Illuminate\Validation\ValidationException::withMessages(['installments' => 'Para dividir el total indica en cuántas cuotas se paga (2 o más).']);
            }
            $totalAmount = $amount;
            $amount = round($totalAmount / (int) $data['installments'], 2);
        }

        $shares = \App\Support\ShareInput::parse($data);

        return [
            'name' => $data['name'], 'amount' => $amount, 'total_amount' => $totalAmount, 'category_id' => $data['category_id'] ?? null,
            'person_id' => $data['person_id'] ?? null, 'due_day' => $dueDay,
            'interval_unit' => $unit, 'interval_count' => $count, 'start_date' => $start?->toDateString(),
            'installments' => $freq === 'monthly' ? null : ($data['installments'] ?? null),
            'duration_months' => $duration,
            'repeat_months' => $duration && $r->boolean('repeat_term') ? $duration : null,
            'shares' => $shares ?: null,
            'share_person_id' => $shares[0]['person_id'] ?? null,
            'share_percent' => $shares ? round(array_sum(array_column($shares, 'percent')), 2) : null,
            'share_mode' => $data['share_mode'] ?? 'split',
        ];
    }

    public function store(Request $r)
    {
        $fixed = $this->household($r)->fixedExpenses()->create($this->attributes($r) + ['user_id' => $r->user()->id]);
        app(FixedShareService::class)->sync($this->household($r), $fixed);
        app(FixedShareService::class)->notifyAssigned($this->household($r), $fixed->fresh());

        return back()->with('ok', 'Gasto fijo agregado: aparecerá en cada vencimiento para marcarlo como pagado.');
    }

    public function update(Request $r, FixedExpense $fixed)
    {
        $this->own($r, $fixed);

        if ($r->has('toggle')) {
            $fixed->update(['active' => ! $fixed->active, 'ended_at' => $fixed->active ? today() : null]);
            app(FixedShareService::class)->sync($this->household($r), $fixed);

            return back()->with('ok', $fixed->active ? 'Gasto fijo reactivado.' : 'Gasto fijo pausado: ya no aparecerá en los próximos vencimientos.');
        }

        $prev = $fixed->participants();
        $fixed->update($this->attributes($r));
        app(FixedShareService::class)->sync($this->household($r), $fixed->fresh());
        app(FixedShareService::class)->notifyAssigned($this->household($r), $fixed->fresh(), $prev);

        return back()->with('ok', 'Gasto fijo actualizado.');
    }

    public function destroy(Request $r, FixedExpense $fixed)
    {
        $this->own($r, $fixed);

        // Cuotas compartidas futuras aún programadas (sin gasto real) y sin pagos: dejan de reflejarse a la otra persona.
        // Lo anterior a hoy no se toca: el historial de meses pasados queda igual.
        $fixed->debts()->whereNull('transaction_id')->whereDoesntHave('payments')
            ->where(fn ($q) => $q->whereNull('fixed_due')->orWhereDate('fixed_due', '>=', today()->toDateString()))
            ->get()->each->delete();

        // Eliminación suave: los meses pasados conservan sus pagos y reportes
        $fixed->update(['ended_at' => $fixed->ended_at ?? today(), 'active' => false]);
        $fixed->delete();

        return back()->with('ok', 'Gasto fijo eliminado. Tus pagos anteriores se conservan en Movimientos.');
    }

    /** Marca una cuota del gasto fijo como pagada: registra el gasto real (con su categoría) y lo comparte si corresponde. */
    public function pay(Request $r, FixedExpense $fixed, Notifier $notifier, ShareService $shares)
    {
        $this->own($r, $fixed);

        $data = $r->validate([
            'due' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
        ]);
        abort_if(empty($data['due']) && empty($data['month']), 422, 'Indica qué vencimiento pagas.');

        // Vencimiento exacto de la cuota (para meses completos se toma el que cae en ese mes)
        if (! empty($data['due'])) {
            $due = Carbon::parse($data['due'])->startOfDay();
        } else {
            $m = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
            $due = optional($fixed->occurrencesBetween($m, $m->copy()->endOfMonth())->first())['due'] ?? null;
            abort_unless($due, 422, 'Este gasto no vence en ese mes.');
        }

        abort_unless($fixed->occurrencesBetween($due, $due)->isNotEmpty(), 422, 'Ese no es un vencimiento de este gasto.');
        abort_if($due->gt(now()->endOfMonth()), 422, 'No puedes pagar un mes futuro.');

        $h = $this->household($r);
        $exists = $h->transactions()->where('fixed_expense_id', $fixed->id)
            ->where(fn ($q) => $q->whereDate('fixed_due', $due->toDateString())
                ->orWhere(fn ($q2) => $q2->whereNull('fixed_due')->where('fixed_month', $due->format('Y-m'))))->exists();
        if ($exists) {
            return back()->with('ok', 'Ya estaba marcado como pagado.');
        }

        // "Otro monto" = total de la cuota; si se reparte, aquí se registra solo TU parte
        $occ = $fixed->occurrencesBetween($due, $due)->first();
        $total = (float) ($data['amount'] ?? $fixed->cuotaAmount($occ['n'] ?? null, $occ['of'] ?? null));
        $amount = $fixed->myAmount($total);
        $split = $fixed->isSplitShared();

        $tx = $h->transactions()->create([
            'user_id' => $r->user()->id,
            'category_id' => $fixed->category_id,
            'person_id' => $fixed->person_id,
            'fixed_expense_id' => $fixed->id,
            'fixed_month' => $due->format('Y-m'),
            'fixed_due' => $due->toDateString(),
            'type' => 'expense',
            'amount' => $amount,
            'total_amount' => $split ? $total : null,
            'currency' => base_currency(),
            'original_amount' => $amount,
            'rate' => null,
            'date' => today(),
            'note' => $fixed->name,
        ]);

        // Pago compartido automáticamente con cada persona configurada (con su % y aviso si tiene cuenta)
        $shared = collect();
        foreach ($fixed->participants() as $part) {
            if (! ($person = $h->people()->with('account')->find($part['person_id']))) {
                continue;
            }
            $scheduled = $h->debts()->where('fixed_expense_id', $fixed->id)->whereDate('fixed_due', $due->toDateString())
                ->where('person_id', $person->id)->first();

            if ($scheduled) {
                // La parte ya estaba programada: se enlaza al gasto real y se ajusta al monto efectivamente pagado
                $share = round($total * $part['percent'] / 100, 2);
                $scheduled->load('payments');
                $scheduled->update([
                    'transaction_id' => $tx->id, 'amount' => max($share, $scheduled->paid()), 'percent' => $part['percent'],
                    'kind' => $split ? 'shared' : 'lent',
                ]);
                $shared->push($scheduled);
            } else {
                $shared->push($shares->create($h, $r->user(), $tx, $person, $part['percent'], $due->toDateString(), $fixed->id, $due->toDateString(), $split ? 'shared' : 'lent', $total));
            }
        }

        $notifier->budgetCheck($h, $tx->category_id, $tx->date);

        return back()->with('ok', "{$fixed->name}: ".($split ? 'tu parte pagada ✓ ('.money($amount).')' : 'pagado ✓').($shared->isNotEmpty() ? ' · '.$shared->map(fn ($d) => "a {$d->person} le toca ".money($d->amount))->join(', ', ' y ') : ''));
    }

    /** Deshace el pago de una cuota (por vencimiento Y-m-d, o por mes Y-m) y las partes compartidas sin pagos. */
    public function unpay(Request $r, FixedExpense $fixed, string $key)
    {
        $this->own($r, $fixed);

        $q = $this->household($r)->transactions()->where('fixed_expense_id', $fixed->id);
        strlen($key) === 10
            ? $q->whereDate('fixed_due', $key)
            : $q->where('fixed_month', $key);

        foreach ($q->get() as $tx) {
            foreach ($tx->shares()->with('payments')->get() as $share) {
                if ($share->fixed_expense_id) {
                    // Cuota programada: vuelve a su monto base, sin ligar a un gasto
                    $pct = collect($fixed->participants())->firstWhere('person_id', $share->person_id)['percent'] ?? (float) $share->percent;
                    $occ = $share->fixed_due ? $fixed->occurrencesBetween($share->fixed_due->copy()->startOfDay(), $share->fixed_due->copy()->startOfDay())->first() : null;
                    $base = round($fixed->cuotaAmount($occ['n'] ?? null, $occ['of'] ?? null) * $pct / 100, 2);
                    $share->update(['transaction_id' => null, 'amount' => max($base, $share->paid())]);
                } else {
                    $share->paid() > 0 ? $share->update(['transaction_id' => null]) : $share->delete();
                }
            }
            $tx->delete();
        }

        return back()->with('ok', 'Pago deshecho.');
    }
}
