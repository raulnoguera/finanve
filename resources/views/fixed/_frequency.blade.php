@php
 $fx = $fixed ?? null;
 $cur = $fx?->frequencyKey() ?? 'monthly';
 $uid = $uid ?? 'new';
@endphp
<div class="grid gap-2 sm:grid-cols-2 sm:col-span-2" x-data="{ f: '{{ old('frequency', $cur) }}' }">
    <div>
        <label class="label" for="fr-{{ $uid }}">¿Cada cuánto se paga?</label>
        <select id="fr-{{ $uid }}" name="frequency" class="field" x-model="f">
            @foreach(\App\Models\FixedExpense::FREQUENCIES as $k => [$label])
                <option value="{{ $k }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div x-show="f === 'monthly'" x-cloak>
        <label class="label" for="dd-{{ $uid }}">Día en que vence</label>
        <input id="dd-{{ $uid }}" class="field num" name="due_day" type="number" min="1" max="31" inputmode="numeric" :disabled="f !== 'monthly'" value="{{ old('due_day', $fx?->due_day ?? 1) }}">
    </div>
    <div x-show="f === 'days'" x-cloak>
        <label class="label" for="id-{{ $uid }}">Cada cuántos días</label>
        <input id="id-{{ $uid }}" class="field num" name="interval_days" type="number" min="1" max="365" inputmode="numeric" :disabled="f !== 'days'" value="{{ old('interval_days', $fx && $fx->interval_unit === 'day' ? $fx->interval_count : 21) }}">
    </div>
    <div x-show="f !== 'monthly'" x-cloak>
        <label class="label" for="sd-{{ $uid }}">Primer vencimiento</label>
        <input id="sd-{{ $uid }}" class="field" name="start_date" type="date" :disabled="f === 'monthly'" value="{{ old('start_date', $fx?->start_date?->toDateString()) }}">
    </div>
    <div x-show="f !== 'monthly'" x-cloak>
        <label class="label" for="du-{{ $uid }}">Duración del periodo (meses) <span class="font-medium">(opcional)</span></label>
        <input id="du-{{ $uid }}" class="field num" name="duration_months" type="number" min="1" max="60" inputmode="numeric" :disabled="f === 'monthly'" placeholder="Ej: 4" value="{{ old('duration_months', $fx?->duration_months) }}">
    </div>
    <label x-show="f !== 'monthly'" x-cloak class="flex min-h-11 items-center gap-2 self-end text-sm font-bold">
        <input type="checkbox" name="repeat_term" value="1" class="size-5" :disabled="f === 'monthly'" @checked(old('repeat_term', $fx?->repeat_months))> Repetir el periodo al terminar
    </label>
    <p x-show="f !== 'monthly'" x-cloak class="text-xs text-muted sm:col-span-2">Ejemplo: cuotas <b>cada 21 días</b> durante un periodo de <b>4 meses</b>, que se repite cada cuatrimestre.</p>
    <div x-show="f !== 'monthly'" x-cloak>
        <label class="label" for="in-{{ $uid }}">Máximo de cuotas por periodo <span class="font-medium">(opcional)</span></label>
        <input id="in-{{ $uid }}" class="field num" name="installments" type="number" min="1" max="120" inputmode="numeric" :disabled="f === 'monthly'" placeholder="Ej: 4" value="{{ old('installments', $fx?->installments) }}">
    </div>
    <div x-show="f !== 'monthly'" x-cloak class="sm:col-span-2">
        <label class="label" for="am-{{ $uid }}">El monto que escribiste es…</label>
        @php $amode = old('amount_mode', $fx?->total_amount ? 'total' : 'per_cuota'); @endphp
        <select id="am-{{ $uid }}" name="amount_mode" class="field" :disabled="f === 'monthly'">
            <option value="per_cuota" @selected($amode === 'per_cuota')>El valor de cada cuota</option>
            <option value="total" @selected($amode === 'total')>El total del gasto (se divide entre las cuotas)</option>
        </select>
        <p class="mt-1 text-xs text-muted">Ejemplo: total <b>100</b> en <b>5 cuotas</b> → cada cuota es 20; si Emely paga el 30 %, le tocan 6 en cada cuota y a ti 14.</p>
    </div>
</div>
