<x-layouts.app title="Movimientos">
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <x-month-nav :month="$month"/>
    <div class="inline-flex rounded-xl bg-surface2 p-1 text-sm font-bold">
        @foreach([null => 'Todos', 'expense' => 'Gastos', 'income' => 'Ingresos', 'saving' => 'Ahorros'] as $k => $l)
            <a href="?mes={{ $month->format('Y-m') }}{{ $k ? '&tipo='.$k : '' }}" class="grid min-h-10 place-items-center rounded-lg px-3 {{ ($type ?? null) == $k ? 'bg-surface shadow-sm' : 'text-muted' }}">{{ $l }}</a>
        @endforeach
    </div>
</div>
@if($people->isNotEmpty())
<section class="card mb-4 p-4">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-extrabold">Por persona <span class="font-medium text-muted">(gastos del mes)</span></h2>
        @if($personId)<a href="?mes={{ $month->format('Y-m') }}{{ $type ? '&tipo='.$type : '' }}" class="min-h-10 text-sm font-bold text-brand">Quitar filtro</a>@endif
    </div>
    <div class="mt-2 flex flex-wrap gap-2">
        @foreach($people as $pp)
            <a href="?mes={{ $month->format('Y-m') }}&tipo=expense&persona={{ $pp->id }}"
               class="chip min-h-10 gap-2 border px-3 text-sm {{ $personId === $pp->id ? 'border-brand bg-brand-soft text-brand' : 'border-line bg-surface hover:border-brand' }}"
               @if($personId === $pp->id) aria-current="true" @endif>
                <span class="size-2.5 rounded-full" style="background:{{ $pp->color }}"></span>
                <span class="max-w-[8rem] truncate">{{ $pp->name }}</span>
                <span class="num font-extrabold">{{ money($personTotals[$pp->id] ?? 0) }}</span>
            </a>
        @endforeach
    </div>
</section>
@endif
@if($places->isNotEmpty() || $place)
<section class="card mb-4 p-4">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-extrabold">Por lugar</h2>
        @if($place)<a href="?mes={{ $month->format('Y-m') }}{{ $type ? '&tipo='.$type : '' }}" class="min-h-10 text-sm font-bold text-brand">Quitar filtro</a>@endif
    </div>
    <div class="mt-2 flex flex-wrap gap-2">
        @foreach($places as $p)
            <a href="?mes={{ $month->format('Y-m') }}{{ $type ? '&tipo='.$type : '' }}&lugar={{ urlencode($p->place) }}"
               class="chip min-h-10 border px-3 text-sm {{ $place === $p->place ? 'border-brand bg-brand-soft text-brand' : 'border-line bg-surface hover:border-brand' }}"
               @if($place === $p->place) aria-current="true" @endif>
                <span class="max-w-[10rem] truncate">{{ $p->place }}</span> <span class="num font-extrabold">{{ money($p->total) }}</span>
            </a>
        @endforeach
    </div>
</section>
@endif
@if($pending->isNotEmpty())
<section class="card mb-4 border-info/40 p-5" aria-labelledby="pend-h">
    <div class="flex items-center justify-between gap-3">
        <h2 id="pend-h" class="font-extrabold">Por pagar <span class="chip ml-1 bg-info/10 text-info">{{ $pending->count() }}</span></h2>
        <span class="num text-sm font-extrabold">{{ money($pending->sum('amount')) }}</span>
    </div>
    <p class="mt-1 text-xs text-muted">Gastos registrados que aún no confirmas. No cuentan en tus saldos ni presupuestos hasta que los marques pagados.</p>
    <ul class="mt-3 divide-y divide-line">
        @foreach($pending as $t)
        <li class="flex flex-wrap items-center gap-3 py-3" x-data="{ pay: false }">
            <div class="min-w-0 flex-1">
                <p class="truncate font-bold">{{ $t->note ?: ($t->category?->fullName() ?? 'Gasto') }}</p>
                <p class="truncate text-xs text-muted">{{ $t->category?->fullName() ?? 'Sin categoría' }}@if($t->person) · {{ $t->person->name }}@endif · {{ $t->date->isFuture() ? 'programado para el '.$t->date->translatedFormat('d M Y') : 'del '.$t->date->translatedFormat('d M') }}</p>
            </div>
            <span class="num text-sm font-extrabold">{{ money($t->amount) }}</span>
            <button type="button" class="btn btn-primary min-h-11" @click="pay = !pay"><x-icon name="check"/> Confirmar pago</button>
            <form method="POST" action="{{ route('transactions.destroy', $t) }}" onsubmit="return confirm('¿Eliminar este gasto por pagar?')">
                @csrf @method('DELETE')
                <button class="grid size-11 place-items-center rounded-xl text-muted hover:text-expense" aria-label="Eliminar">✕</button>
            </form>
            <form x-show="pay" x-cloak method="POST" action="{{ route('transactions.confirm', $t) }}" class="flex w-full flex-wrap items-end gap-2 rounded-xl bg-surface2 p-3">
                @csrf
                <div>
                    <label class="label" for="pd-{{ $t->id }}">Fecha en que pagaste</label>
                    <input id="pd-{{ $t->id }}" type="date" name="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="field">
                </div>
                <button class="btn btn-primary min-h-11">Marcar como pagado</button>
            </form>
        </li>
        @endforeach
    </ul>
</section>
@endif
<section class="card p-5">
    @php $sum = $transactions->sum(fn($t) => $t->type === 'income' ? $t->amount : -$t->amount); @endphp
    <div class="flex items-center justify-between text-sm"><span class="font-bold text-muted">{{ $transactions->count() }} {{ $transactions->count() === 1 ? 'movimiento' : 'movimientos' }}@if($place) · {{ $place }}@endif @if($personId) · {{ $people->firstWhere('id', $personId)?->name }}@endif</span><span class="num font-extrabold">{{ usd($sum, true) }}</span></div>
    @include('transactions._list', ['deletable' => true])
</section>
</x-layouts.app>
