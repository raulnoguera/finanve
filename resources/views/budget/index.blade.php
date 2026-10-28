<x-layouts.app title="Presupuesto">
@php
 $hasSubs = $expense->contains(fn ($c) => $c->children->isNotEmpty());
 $bar = fn ($s, $b) => $b > 0 ? min(100, $s / $b * 100) : 0;
 $tone = fn ($s, $b) => $b > 0 && $s > $b ? 'var(--expense)' : ($b > 0 && $s / $b > .8 ? 'var(--warn)' : 'var(--brand)');
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <p class="max-w-md text-sm text-muted">Fija un límite mensual en {{ config('currencies.'.cur().'.name') }} por categoría o por subcategoría. Una categoría suma lo de sus subcategorías.</p>
    <x-month-nav :month="$month"/>
</div>

@unless($hasSubs)
<div class="card mb-4 flex flex-wrap items-center justify-between gap-3 p-4" style="background:var(--brand-soft)">
    <p class="min-w-0 flex-1 text-sm"><b>Detalla tus gastos:</b> agrega subcategorías sugeridas como Restaurantes, Colegio o Víveres.</p>
    <form method="POST" action="{{ route('categories.suggested') }}">@csrf<input type="hidden" name="type" value="expense"><button class="btn btn-primary">Agregar sugeridas</button></form>
</div>
@endunless

<div class="grid gap-3">
@foreach($expense as $c)
    @php
        $total = $rollup[$c->id] ?? 0; $own = $direct[$c->id] ?? 0; $b = (float) $c->monthly_budget;
        $over = $b > 0 && $total > $b;
    @endphp
    <section class="card" x-data="{ open: {{ $c->children->isNotEmpty() && $total > 0 ? 'true' : 'false' }}, edit: false, add: false }">
        <div class="flex items-center gap-3 p-4">
            <span class="size-3 shrink-0 rounded-full" style="background:{{ $c->color }}"></span>
            <button type="button" class="min-w-0 flex-1 text-left" @click="open = !open" :aria-expanded="open">
                <p class="flex items-center gap-1.5 font-bold">{{ $c->name }}
                    @if($c->children->isNotEmpty())<span class="chip bg-surface2 text-muted">{{ $c->children->count() }}</span>
                        <x-icon name="chev-right" class="size-4 text-muted transition" x-bind:class="open && 'rotate-90'"/>@endif
                </p>
                <p class="num text-sm {{ $over ? 'font-bold text-expense' : 'text-muted' }}">
                    {{ money($total) }} gastado{{ $b > 0 ? ' de '.money($b) : '' }}
                    @if($over) · te pasaste por {{ money($total - $b) }} @elseif($b > 0) · quedan {{ money($b - $total) }} @endif
                </p>
            </button>
            <button type="button" class="btn btn-ghost !min-h-10 !px-3" @click="edit = !edit">Límite</button>
            <form method="POST" action="{{ route('categories.destroy', $c) }}" onsubmit="return confirm('¿Eliminar {{ $c->name }}{{ $c->children->isNotEmpty() ? ' y sus subcategorías' : '' }}? Sus movimientos quedarán sin categoría.')">@csrf @method('DELETE')
                <button class="grid size-10 place-items-center rounded-lg text-muted hover:text-expense" aria-label="Eliminar {{ $c->name }}"><x-icon name="trash" class="size-4"/></button>
            </form>
        </div>
        @if($b > 0)
            <div class="mx-4 mb-3 h-2.5 overflow-hidden rounded-full bg-surface2" role="progressbar" aria-valuenow="{{ round($bar($total, $b)) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Uso del presupuesto de {{ $c->name }}">
                <div class="h-full rounded-full" style="width:{{ $bar($total, $b) }}%;background:{{ $tone($total, $b) }}"></div>
            </div>
        @endif
        <form x-show="edit" x-cloak method="POST" action="{{ route('categories.update', $c) }}" class="mx-4 mb-4 flex gap-2">
            @csrf @method('PATCH')
            <input class="field num" type="number" step="0.01" min="0" name="monthly_budget" value="{{ $b ?: '' }}" placeholder="Límite mensual en {{ cur() }}" aria-label="Límite mensual de {{ $c->name }} en {{ cur() }}">
            <button class="btn btn-primary">Guardar</button>
        </form>

        {{-- Subcategorías --}}
        <div x-show="open" x-cloak class="border-t border-line bg-surface2/40">
            @if($c->children->isNotEmpty() && $own > 0)
                <p class="num flex justify-between px-4 py-2.5 pl-10 text-sm text-muted"><span>Sin subcategoría</span><span class="font-bold text-ink">{{ money($own) }}</span></p>
            @endif
            @foreach($c->children as $k)
                @php $ks = $direct[$k->id] ?? 0; $kb = (float) $k->monthly_budget; $kover = $kb > 0 && $ks > $kb; @endphp
                <div class="border-t border-line/60 px-4 py-3 pl-10 first:border-t-0" x-data="{ kedit: false }">
                    <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold">{{ $k->name }}</p>
                            <p class="num text-xs {{ $kover ? 'font-bold text-expense' : 'text-muted' }}">
                                {{ money($ks) }}{{ $kb > 0 ? ' de '.money($kb) : '' }}@if($kover) · +{{ money($ks - $kb) }} @endif
                            </p>
                        </div>
                        <button type="button" class="px-2 text-xs font-bold text-brand" @click="kedit = !kedit">Límite</button>
                        <form method="POST" action="{{ route('categories.destroy', $k) }}" onsubmit="return confirm('¿Eliminar la subcategoría {{ $k->name }}? Sus movimientos quedarán sin categoría.')">@csrf @method('DELETE')
                            <button class="grid size-9 place-items-center rounded-lg text-muted hover:text-expense" aria-label="Eliminar {{ $k->name }}"><x-icon name="trash" class="size-4"/></button>
                        </form>
                    </div>
                    @if($kb > 0)
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-border" role="progressbar" aria-valuenow="{{ round($bar($ks, $kb)) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Uso de {{ $k->name }}"><div class="h-full rounded-full" style="width:{{ $bar($ks, $kb) }}%;background:{{ $tone($ks, $kb) }}"></div></div>
                    @endif
                    <form x-show="kedit" x-cloak method="POST" action="{{ route('categories.update', $k) }}" class="mt-2 flex gap-2">
                        @csrf @method('PATCH')
                        <input class="field num" type="number" step="0.01" min="0" name="monthly_budget" value="{{ $kb ?: '' }}" placeholder="Límite mensual en {{ cur() }}" aria-label="Límite mensual de {{ $k->name }}">
                        <button class="btn btn-primary">Guardar</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="border-t border-line px-4 py-2.5">
            <button type="button" class="flex min-h-10 items-center gap-1.5 text-sm font-bold text-brand" @click="add = !add; open = true"><x-icon name="plus" class="size-4"/> Subcategoría</button>
            <form x-show="add" x-cloak method="POST" action="{{ route('categories.store') }}" class="mt-2 grid gap-2 pb-2 sm:grid-cols-[1fr_9rem_auto]">
                @csrf
                <input type="hidden" name="parent_id" value="{{ $c->id }}">
                <input class="field" name="name" maxlength="40" required placeholder="Ej: {{ ['Comida' => 'Restaurante El Fogón', 'Educación' => 'Colegio de María', 'Mercado' => 'Panadería'][$c->name] ?? 'Nombre' }}" aria-label="Nombre de la subcategoría de {{ $c->name }}">
                <input class="field num" type="number" step="0.01" min="0" name="monthly_budget" placeholder="Límite (opcional)" aria-label="Límite mensual">
                <button class="btn btn-primary">Crear</button>
            </form>
        </div>
    </section>
@endforeach
</div>

@if($hasSubs)
<form method="POST" action="{{ route('categories.suggested') }}" class="mt-3 text-right">@csrf<input type="hidden" name="type" value="expense"><button class="text-xs font-bold text-muted hover:text-brand">Agregar subcategorías de gastos que falten</button></form>
@endif

<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Nueva categoría</h2>
    <form method="POST" action="{{ route('categories.store') }}" class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto_auto_auto]">
        @csrf
        <input class="field" name="name" placeholder="Ej: Mascotas" maxlength="40" required aria-label="Nombre de la categoría">
        <select class="field" name="type" aria-label="Tipo"><option value="expense">Gasto</option><option value="income">Ingreso</option></select>
        <input class="field !w-16 p-1" type="color" name="color" value="#6366f1" aria-label="Color">
        <button class="btn btn-primary">Crear</button>
    </form>
</section>

@php
 $incomeSuggestions = collect(['Bono', 'Freelance', 'Remesas', 'Alquileres', 'Ventas', 'Intereses', 'Pensión', 'Regalos'])
     ->reject(fn ($n) => $income->contains(fn ($c) => mb_strtolower($c->name) === mb_strtolower($n)
         || $c->children->contains(fn ($k) => mb_strtolower($k->name) === mb_strtolower($n))));
 $incomeHasSubs = $income->contains(fn ($c) => $c->children->isNotEmpty());
@endphp
<section class="card mt-4 p-5">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="font-extrabold">Categorías de ingreso</h2>
            <p class="text-sm text-muted">De dónde entra el dinero. Puedes detallarlo con subcategorías (Salario › Quincena, Bono…).</p>
        </div>
        <form method="POST" action="{{ route('categories.suggested') }}">@csrf<input type="hidden" name="type" value="income">
            <button class="text-xs font-bold text-muted hover:text-brand">{{ $incomeHasSubs ? 'Agregar subcategorías sugeridas que falten' : 'Agregar subcategorías sugeridas' }}</button>
        </form>
    </div>

    <ul class="mt-3 divide-y divide-line">
        @foreach($income as $c)
            @php $tot = $incomeSums['rollup'][$c->id] ?? 0; $own = $incomeSums['direct'][$c->id] ?? 0; @endphp
            <li class="py-2" x-data="{ edit: false, add: false }">
                <div class="flex items-center gap-3">
                    <span class="size-3 shrink-0 rounded-full" style="background:{{ $c->color }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-bold">{{ $c->name }} @if($c->children->isNotEmpty())<span class="chip bg-surface2 text-muted">{{ $c->children->count() }}</span>@endif</span>
                        @if($tot > 0)<span class="num block text-xs text-income">{{ money($tot) }} este mes</span>@endif
                    </span>
                    <button type="button" class="min-h-10 px-2 text-xs font-bold text-brand" @click="add = !add">+ Subcategoría</button>
                    <button type="button" class="min-h-10 px-1 text-xs font-bold text-muted hover:text-brand" @click="edit = !edit">Renombrar</button>
                    <form method="POST" action="{{ route('categories.destroy', $c) }}" onsubmit="return confirm('¿Eliminar {{ $c->name }}{{ $c->children->isNotEmpty() ? ' y sus subcategorías' : '' }}? Sus ingresos quedarán sin categoría.')">@csrf @method('DELETE')
                        <button class="grid size-10 place-items-center rounded-lg text-muted hover:text-expense" aria-label="Eliminar {{ $c->name }}"><x-icon name="trash" class="size-4"/></button>
                    </form>
                </div>

                @foreach($c->children as $k)
                    @php $ks = $incomeSums['direct'][$k->id] ?? 0; @endphp
                    <div class="ml-6 border-l-2 border-line pl-3" x-data="{ kedit: false }">
                        <div class="flex items-center gap-2">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold">{{ $k->name }}</span>
                                @if($ks > 0)<span class="num block text-xs text-income">{{ money($ks) }}</span>@endif
                            </span>
                            <button type="button" class="min-h-10 px-1 text-xs font-bold text-muted hover:text-brand" @click="kedit = !kedit">Renombrar</button>
                            <form method="POST" action="{{ route('categories.destroy', $k) }}" onsubmit="return confirm('¿Eliminar la subcategoría {{ $k->name }}? Sus ingresos quedarán sin categoría.')">@csrf @method('DELETE')
                                <button class="grid size-9 place-items-center rounded-lg text-muted hover:text-expense" aria-label="Eliminar {{ $k->name }}"><x-icon name="trash" class="size-4"/></button>
                            </form>
                        </div>
                        <form x-show="kedit" x-cloak method="POST" action="{{ route('categories.update', $k) }}" class="mb-2 flex gap-2">
                            @csrf @method('PATCH')
                            <input class="field" name="name" value="{{ $k->name }}" maxlength="40" required aria-label="Nuevo nombre de {{ $k->name }}">
                            <button class="btn btn-primary">Guardar</button>
                        </form>
                    </div>
                @endforeach
                @if($c->children->isNotEmpty() && $own > 0)
                    <p class="num ml-6 border-l-2 border-line pl-3 text-xs text-muted">General: {{ money($own) }}</p>
                @endif

                <form x-show="edit" x-cloak method="POST" action="{{ route('categories.update', $c) }}" class="mt-2 flex gap-2">
                    @csrf @method('PATCH')
                    <input class="field" name="name" value="{{ $c->name }}" maxlength="40" required aria-label="Nuevo nombre de {{ $c->name }}">
                    <button class="btn btn-primary">Guardar</button>
                </form>
                <form x-show="add" x-cloak method="POST" action="{{ route('categories.store') }}" class="mt-2 flex gap-2">
                    @csrf
                    <input type="hidden" name="parent_id" value="{{ $c->id }}">
                    <input class="field" name="name" maxlength="40" required placeholder="Ej: {{ ['Salario' => 'Quincena', 'Otros ingresos' => 'Remesas'][$c->name] ?? 'Nombre' }}" aria-label="Nombre de la subcategoría de {{ $c->name }}">
                    <button class="btn btn-primary shrink-0">Crear</button>
                </form>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('categories.store') }}" class="mt-3 flex gap-2">
        @csrf
        <input type="hidden" name="type" value="income">
        <label class="sr-only" for="inc-name">Nueva categoría de ingreso</label>
        <input id="inc-name" class="field" name="name" maxlength="40" required placeholder="Nueva categoría de ingreso">
        <button class="btn btn-primary shrink-0"><x-icon name="plus" class="size-4"/> Agregar</button>
    </form>

    @if($incomeSuggestions->isNotEmpty())
        <p class="mt-4 text-xs font-bold text-muted">Sugeridas (un toque para agregar)</p>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach($incomeSuggestions as $n)
                <form method="POST" action="{{ route('categories.store') }}">@csrf
                    <input type="hidden" name="type" value="income"><input type="hidden" name="name" value="{{ $n }}">
                    <button class="chip min-h-10 border border-line bg-surface px-3 text-sm hover:border-brand hover:text-brand"><x-icon name="plus" class="size-3.5"/> {{ $n }}</button>
                </form>
            @endforeach
        </div>
    @endif
</section>
</x-layouts.app>
