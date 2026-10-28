@php $fx = $fixed ?? null; $uid = $uid ?? 'new'; @endphp
@php
    $sharePeople = $people->whereNull('member_user_id');
    $initial = old('shares', collect($fx?->participants() ?? [])->map(fn ($p) => ['person_id' => $p['person_id'], 'percent' => $p['percent'] + 0])->all());
    if (empty($initial) && old('share_person_id')) { $initial = [['person_id' => old('share_person_id'), 'percent' => old('share_percent')]]; }
    $initial = array_values($initial);
@endphp
@if($sharePeople->isNotEmpty())
<div class="grid gap-2 sm:col-span-2 rounded-xl bg-surface2 p-3"
     x-data="{ rows: {{ \Illuminate\Support\Js::from($initial) }}, add() { if (this.rows.length < 10) this.rows.push({ person_id: '', percent: '' }) }, total() { return this.rows.reduce((t, r) => t + (parseFloat(r.percent) || 0), 0) } }">
    <p class="label">Compartir cada pago con <span class="font-medium">(opcional, puedes elegir varias personas)</span></p>

    <template x-for="(row, i) in rows" :key="i">
        <div class="grid grid-cols-[1fr_6.5rem_auto] items-end gap-2">
            <div>
                <label class="sr-only" :for="'shp-{{ $uid }}-' + i">Persona</label>
                <select :id="'shp-{{ $uid }}-' + i" :name="'shares[' + i + '][person_id]'" class="field" x-model="row.person_id">
                    <option value="">Elige una persona</option>
                    @foreach($sharePeople as $pp)<option value="{{ $pp->id }}">{{ $pp->name }}{{ $pp->user_id ? ' · con cuenta' : '' }}</option>@endforeach
                </select>
            </div>
            <div class="relative">
                <label class="sr-only" :for="'shc-{{ $uid }}-' + i">Porcentaje</label>
                <input :id="'shc-{{ $uid }}-' + i" class="field num pr-7" :name="'shares[' + i + '][percent]'" x-model="row.percent" type="number" step="0.01" min="0.01" max="100" inputmode="decimal" placeholder="40">
                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm font-bold text-muted">%</span>
            </div>
            <button type="button" class="grid size-11 place-items-center rounded-xl text-muted hover:text-expense" @click="rows.splice(i, 1)" aria-label="Quitar persona">✕</button>
        </div>
    </template>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <button type="button" class="min-h-10 text-sm font-bold text-brand" @click="add()" x-text="rows.length ? '+ Agregar otra persona' : '+ Compartir con alguien'"></button>
        <p class="text-xs text-muted" x-show="rows.length" x-cloak>
            Ellos pagan <b x-text="total().toFixed(2).replace(/\.?0+$/, '') + ' %'"></b> · tú <b x-text="Math.max(0, 100 - total()).toFixed(2).replace(/\.?0+$/, '') + ' %'"></b>
            <span class="font-bold text-expense" x-show="total() > 100.001">· suman más de 100 %</span>
        </p>
    </div>

    <fieldset class="sm:col-span-2" x-show="rows.length" x-cloak :disabled="!rows.length">
        <legend class="label">¿Cómo se paga?</legend>
        @php $mode = old('share_mode', $fx?->share_mode ?? 'split'); @endphp
        <div class="grid gap-2 sm:grid-cols-2">
            <label class="flex cursor-pointer gap-2 rounded-xl border border-line bg-surface p-3 text-sm has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                <input type="radio" name="share_mode" value="split" class="mt-0.5 size-4" @checked($mode === 'split')>
                <span><b>Cada quien paga su parte</b><br><span class="text-xs text-muted">Tu gasto es solo tu porcentaje. La cuota se completa cuando todos pagan.</span></span>
            </label>
            <label class="flex cursor-pointer gap-2 rounded-xl border border-line bg-surface p-3 text-sm has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                <input type="radio" name="share_mode" value="reimburse" class="mt-0.5 size-4" @checked($mode === 'reimburse')>
                <span><b>Yo pago el total y me reembolsan</b><br><span class="text-xs text-muted">Tu gasto es el total; los demás te devuelven su parte.</span></span>
            </label>
        </div>
    </fieldset>
    <p class="text-xs text-muted" x-show="rows.length" x-cloak>Cada persona verá su parte (y si tiene cuenta, la verá en Compartidos). La cuota se completa cuando todos pagan.</p>
</div>
@endif
