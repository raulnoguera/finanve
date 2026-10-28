<x-layouts.app title="Gastos fijos">
@php
 $pct = $totals['expected'] > 0 ? min(100, $totals['paid'] / $totals['expected'] * 100) : 0;
 $chip = ['paid' => ['Pagado', 'bg-brand-soft text-brand'], 'pending' => ['Pendiente', 'bg-surface2 text-muted'], 'overdue' => ['Vencido', 'bg-expense/10 text-expense']];
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <p class="max-w-md text-sm text-muted">Tus pagos de todos los meses, precargados. Solo marca cada uno cuando lo pagues.</p>
    <x-month-nav :month="$month"/>
</div>

{{-- Resumen del mes --}}
<section class="card rise p-5" style="background:linear-gradient(135deg,var(--brand-soft),var(--surface))">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-sm font-bold text-muted">Tu parte pagada este mes</p>
            <x-money :usd="$totals['paid']" size="text-3xl"/>
        </div>
        <div class="text-right">
            <p class="text-sm font-bold text-muted">Falta pagar</p>
            <x-money :usd="$totals['pending']" size="text-xl" :tone="$totals['overdue_count'] ? 'text-expense' : 'text-ink'" class="items-end"/>
        </div>
    </div>
    <div class="mt-4 h-3 overflow-hidden rounded-full bg-surface" role="progressbar" aria-valuenow="{{ round($pct) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Gastos fijos pagados">
        <div class="h-full rounded-full bg-brand transition-all" style="width:{{ $pct }}%"></div>
    </div>
    <p class="mt-2 text-sm text-muted num">
        {{ $totals['paid_count'] }} de {{ $totals['count'] }} {{ $totals['paid_count'] === 1 ? 'pagado' : 'pagados' }}
        @if($totals['awaiting_count']) · <b class="text-info">{{ $totals['awaiting_count'] }} {{ $totals['awaiting_count'] == 1 ? 'espera' : 'esperan' }} el pago de otros</b> @endif
        @if($totals['overdue_count']) · <b class="text-expense">{{ $totals['overdue_count'] }} {{ $totals['overdue_count'] == 1 ? 'vencido' : 'vencidos' }}</b> @endif
    </p>
</section>

{{-- Lista --}}
<section class="mt-4 grid gap-3">
@forelse($rows as $row)
    @php
        $f = $row['fixed']; $st = $row['status'];
        $awaiting = $st === 'paid' && $row['waiting']->isNotEmpty();
        $chipKey = $awaiting ? 'awaiting' : ($st === 'paid' && $f->share_person_id ? 'complete' : $st);
        $chip['awaiting'] = ['Tu parte pagada', 'bg-info/10 text-info'];
        $chip['complete'] = ['Completado', 'bg-brand-soft text-brand'];
    @endphp
    <article class="card min-w-0 p-4 {{ $st === 'paid' && ! $awaiting ? 'bg-surface2/50' : '' }}" x-data="{ custom: false, edit: false }">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 grid size-10 shrink-0 place-items-center rounded-full {{ $awaiting ? 'bg-info/15 text-info' : ($st === 'paid' ? 'bg-brand text-white dark:text-[#062018]' : ($st === 'overdue' ? 'bg-expense/10 text-expense' : 'bg-surface2 text-muted')) }}">
                <x-icon :name="$awaiting ? 'users' : ($st === 'paid' ? 'check' : ($st === 'overdue' ? 'alert' : 'clock'))" class="size-5"/>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate font-extrabold {{ $st === 'paid' && ! $awaiting ? 'line-through decoration-1 opacity-70' : '' }}">{{ $f->name }}@if($row['n']) <span class="text-xs font-bold text-muted">· cuota {{ $row['n'] }}{{ $row['of'] ? ' de '.$row['of'] : '' }}</span>@endif</p>
                <p class="truncate text-xs text-muted">
                    {{ $f->frequencyLabel() }}{{ $f->termLabel() ? ' '.$f->termLabel() : '' }} · {{ $f->category?->fullName() ?? 'Sin categoría' }}@if($f->person) · {{ $f->person->name }}@endif · vence el {{ $row['due']->translatedFormat('d M') }}@if($f->share_person_id) · comparte con {{ collect($f->participants())->map(fn ($pp) => ($people->firstWhere('id', $pp['person_id'])?->name ?? 'otra persona').' ('.($pp['percent'] + 0).' %)')->join(', ', ' y ') }}@endif
                    @if($row['tx']) · pagado el {{ $row['tx']->date->translatedFormat('d M') }} @endif
                </p>
                @if($f->total_amount)
                    <p class="mt-0.5 text-xs text-muted">Total del gasto {{ money($f->total_amount) }} en {{ $f->installments }} cuotas de {{ money($f->amount) }}</p>
                @endif
                @if($f->isSplitShared())
                    <p class="mt-0.5 text-xs text-muted">Total de la cuota {{ money($row['tx']->total_amount ?? $f->amount) }} · a ti te toca <b class="text-ink">{{ money($row['tx']->amount ?? $f->myAmount()) }}</b> ({{ 100 - ($f->share_percent + 0) }} %)</p>
                @endif
                @php $cal = $calendars->get($f->id); @endphp
                @if($cal && $cal->isNotEmpty())
                    <p class="num mt-0.5 text-xs text-muted">{{ $cal->where('paid', true)->count() }} de {{ $cal->count() }} cuotas pagadas · termina el <b class="text-ink">{{ $cal->last()['due']->translatedFormat('d M Y') }}</b></p>
                    <details class="mt-1 text-xs">
                        <summary class="min-h-8 cursor-pointer font-bold text-brand">Ver todas las cuotas</summary>
                        <ol class="num mt-1 grid gap-0.5 rounded-lg bg-surface2 p-2">
                            @foreach($cal as $c)
                                <li class="flex items-center justify-between gap-3 {{ $c['paid'] ? 'text-muted' : '' }}">
                                    <span>{{ $c['n'] }}/{{ $c['of'] }} · {{ $c['due']->translatedFormat('d M Y') }}</span>
                                    <span class="flex items-center gap-2"><b>{{ money($f->myAmount($c['amount'])) }}</b>
                                        <span class="chip {{ $c['paid'] ? 'bg-brand-soft text-brand' : ($c['due']->isPast() ? 'bg-expense/10 text-expense' : 'bg-surface text-muted') }}">{{ $c['paid'] ? 'Pagada' : ($c['due']->isPast() ? 'Vencida' : 'Pendiente') }}</span></span>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                @endif
                @if($awaiting)
                    <p class="mt-0.5 text-xs font-bold text-info">Falta que paguen su parte: {{ $row['waiting']->join(', ', ' y ') }}</p>
                @elseif($st === 'paid' && $f->share_person_id)
                    <p class="mt-0.5 text-xs font-bold text-brand">Todos pagaron su parte ✓</p>
                @endif
            </div>
            <div class="text-right">
                <x-money :usd="$row['tx']->amount ?? $f->myAmount()" size="text-base" class="items-end"/>
                <span class="chip mt-1 {{ $chip[$chipKey][1] }}">{{ $chip[$chipKey][0] }}</span>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if($st === 'paid')
                <form method="POST" action="{{ route('fixed.unpay', [$f, $row['key']]) }}" onsubmit="return confirm('¿Deshacer el pago? Se eliminará el gasto registrado.')">@csrf @method('DELETE')
                    <button class="btn btn-ghost !min-h-10 !px-3 text-sm">Deshacer pago</button>
                </form>
            @else
                <form method="POST" action="{{ route('fixed.pay', $f) }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="due" value="{{ $row['key'] }}">
                    <input x-show="custom" x-cloak :disabled="!custom" class="field num !min-h-10 !w-32" name="amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="{{ $f->amount + 0 }}" aria-label="Total de la cuota de {{ $f->name }}">
                    <button class="btn btn-primary !min-h-11"><x-icon name="check" class="size-4"/> {{ $f->isSplitShared() ? 'Marcar mi parte pagada' : 'Marcar pagado' }}</button>
                    <button type="button" class="text-xs font-bold text-muted hover:text-brand" @click="custom = !custom" x-text="custom ? 'Usar monto habitual' : ('{{ $f->isSplitShared() ? 'Otro total' : 'Otro monto' }}')"></button>
                </form>
            @endif
            <button type="button" class="ml-auto text-xs font-bold text-muted hover:text-brand" @click="edit = !edit">Editar</button>
        </div>

        <form x-show="edit" x-cloak method="POST" action="{{ route('fixed.update', $f) }}" class="mt-3 grid gap-2 border-t border-line pt-3 sm:grid-cols-2">
            @csrf @method('PATCH')
            <div><label class="label" for="n{{ $f->id }}">Nombre</label><input id="n{{ $f->id }}" class="field" name="name" value="{{ $f->name }}" maxlength="60" required></div>
            <div><label class="label" for="a{{ $f->id }}">Monto ({{ cur() }}) <span class="font-medium text-muted">según lo elegido abajo</span></label><input id="a{{ $f->id }}" class="field num" name="amount" type="number" step="0.01" min="0.01" value="{{ ($f->total_amount ?? $f->amount) + 0 }}" required></div>
            @include('fixed._frequency', ['fixed' => $f, 'uid' => 'e'.$f->id])
            <div><label class="label" for="c{{ $f->id }}">Categoría</label>
                <select id="c{{ $f->id }}" name="category_id" class="field">
                    <option value="">Sin categoría</option>
                    @foreach($cats->whereNull('parent_id') as $c)
                        @if($kids->has($c->id))
                            <optgroup label="{{ $c->name }}"><option value="{{ $c->id }}" @selected($f->category_id === $c->id)>{{ $c->name }} (general)</option>
                                @foreach($kids[$c->id] as $k)<option value="{{ $k->id }}" @selected($f->category_id === $k->id)>{{ $k->name }}</option>@endforeach
                            </optgroup>
                        @else
                            <option value="{{ $c->id }}" @selected($f->category_id === $c->id)>{{ $c->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div><label class="label" for="p{{ $f->id }}">¿Para quién?</label>
                <select id="p{{ $f->id }}" name="person_id" class="field">
                    <option value="">Para la familia</option>
                    @foreach($people as $pp)<option value="{{ $pp->id }}" @selected($f->person_id === $pp->id)>{{ $pp->formLabel(auth()->id()) }}</option>@endforeach
                </select>
            </div>
            @include('fixed._share', ['fixed' => $f, 'uid' => 'e'.$f->id])
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                <button class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
        <div x-show="edit" x-cloak class="mt-2 flex gap-4 text-xs font-bold">
            <form method="POST" action="{{ route('fixed.update', $f) }}">@csrf @method('PATCH')<input type="hidden" name="toggle" value="1"><button class="text-muted hover:text-brand">Pausar (dejar de mostrarlo)</button></form>
            <form method="POST" action="{{ route('fixed.destroy', $f) }}" onsubmit="return confirm('¿Eliminar {{ $f->name }}? Los pagos ya registrados se conservan.')">@csrf @method('DELETE')<button class="text-muted hover:text-expense">Eliminar</button></form>
        </div>
    </article>
@empty
    <div class="card p-8 text-center">
        <x-icon name="repeat" class="mx-auto size-10 text-brand"/>
        <p class="mt-3 font-extrabold">Aún no tienes gastos fijos</p>
        <p class="text-sm text-muted">Agrega el alquiler, los servicios, el colegio… y cada mes los tendrás listos para marcar como pagados.</p>
    </div>
@endforelse
</section>

@if($inactive->isNotEmpty())
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Pausados</h2>
    <ul class="mt-2 divide-y divide-line">
        @foreach($inactive as $f)
            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                <span class="min-w-0 truncate">{{ $f->name }} <span class="num text-muted">· {{ money($f->amount) }}</span></span>
                <form method="POST" action="{{ route('fixed.update', $f) }}">@csrf @method('PATCH')<input type="hidden" name="toggle" value="1"><button class="font-bold text-brand">Reactivar</button></form>
            </li>
        @endforeach
    </ul>
</section>
@endif

{{-- Nuevo --}}
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Agregar gasto fijo</h2>
    <form method="POST" action="{{ route('fixed.store') }}" class="mt-3 grid gap-3 sm:grid-cols-2">
        @csrf
        <div class="sm:col-span-2">
            <label class="label" for="fx-name">Nombre</label>
            <input id="fx-name" class="field" name="name" list="fx-suggested" maxlength="60" required placeholder="Ej: Alquiler">
            <datalist id="fx-suggested">@foreach($suggested as $s)<option value="{{ $s }}">@endforeach</datalist>
        </div>
        <div><label class="label" for="fx-amount">Monto ({{ cur() }}) <span class="font-medium text-muted">si no es mensual, elige abajo si es por cuota o el total</span></label><input id="fx-amount" class="field num" name="amount" type="number" step="0.01" min="0.01" inputmode="decimal" required></div>
        @include('fixed._frequency', ['uid' => 'n'])
        <div class="sm:col-span-2">
            <label class="label" for="fx-cat">Categoría</label>
            <select id="fx-cat" name="category_id" class="field">
                <option value="">Sin categoría</option>
                @foreach($cats->whereNull('parent_id') as $c)
                    @if($kids->has($c->id))
                        <optgroup label="{{ $c->name }}"><option value="{{ $c->id }}">{{ $c->name }} (general)</option>
                            @foreach($kids[$c->id] as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                        </optgroup>
                    @else
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endif
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="fx-person">¿Para quién? <span class="font-medium">(opcional)</span></label>
            <select id="fx-person" name="person_id" class="field">
                <option value="">Para la familia</option>
                @foreach($people as $pp)<option value="{{ $pp->id }}">{{ $pp->formLabel(auth()->id()) }}</option>@endforeach
            </select>
            @if($people->whereNull('member_user_id')->isEmpty())<p class="mt-1 text-xs text-muted">Agrega a tus hijos o pareja en <a class="font-bold text-brand" href="{{ route('family.index') }}">Familia</a> para asignarles gastos.</p>@endif
        </div>
        @include('fixed._share', ['uid' => 'n'])
        <button class="btn btn-primary sm:col-span-2">Agregar</button>
    </form>
    <p class="mt-3 text-xs text-muted">Si algo cambia de un pago a otro (por ejemplo la luz), usa <b>Otro monto</b> al marcarlo como pagado. Para pagos como la universidad (cuatrimestral o cada 21 días) elige la frecuencia y la fecha del primer vencimiento.</p>
</section>
</x-layouts.app>
