@php
 $nav = [
  ['dashboard', 'Inicio', 'home'],
  ['transactions.index', 'Movimientos', 'list'],
  ['market.index', 'Mercado', 'cart'],
  ['budget.index', 'Presupuesto', 'pie'],
  ['fixed.index', 'Gastos fijos', 'repeat'],
  ['goals.index', 'Metas', 'target'],
  ['reports.index', 'Reportes', 'chart'],
  ['debts.index', 'Deudas', 'swap'],
  ['family.index', 'Familia', 'users'],
 ];
 if (auth()->user()->linkedPeople()->exists()) { $nav[] = ['shared.index', 'Compartidos', 'share']; }
 $shareRequests = \App\Models\Invitation::pendingFor(auth()->user())->with('inviter', 'person')->get();
 $r = rate();
 $cats = auth()->user()->household->categories()->orderBy('name')->get();
 $kids = $cats->whereNotNull('parent_id')->groupBy('parent_id');
 $meId = auth()->id();
 $peopleList = auth()->user()->household->people()->orderByRaw('member_user_id = '.(int) $meId.' desc')->orderBy('name')->get();
 $sharePeople = $peopleList->whereNull('member_user_id');
 $placeList = auth()->user()->household->transactions()->whereNotNull('place')->groupBy('place')->orderByRaw('MAX(id) DESC')->limit(40)->pluck('place');
 $pageTitle = $title ?? 'Inicio';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.brand-head')
    <title>{{ $pageTitle }} · {{ config('app.name') }}</title>
    @fonts
    <script>window.FIN = { fmt: @json(config('currencies.'.base_currency())) };</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh" x-data="{ ...theme(), newTx: false, more: false }" @keydown.escape.window="newTx = false; more = false" @open-tx.window="newTx = true">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-surface focus:px-3 focus:py-2">Saltar al contenido</a>

{{-- Sidebar escritorio --}}
<aside class="fixed inset-y-0 left-0 hidden w-64 flex-col border-r border-line bg-surface p-4 lg:flex">
    <div class="mb-6 flex items-center gap-3 px-2 pt-2">
        <img src="{{ asset('brand/mark.svg') }}" alt="" width="40" height="40" class="size-10 rounded-xl">
        <div class="leading-tight"><div class="font-extrabold">{{ config('app.name') }}</div><div class="text-xs text-muted">{{ auth()->user()->household->name }}</div></div>
    </div>
    <nav class="grid gap-1" aria-label="Principal">
        @foreach($nav as [$route, $label, $icon])
            @php $on = request()->routeIs($route); @endphp
            <a href="{{ route($route) }}" @if($on) aria-current="page" @endif
               class="flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-bold transition-colors {{ $on ? 'bg-brand-soft text-brand' : 'text-muted hover:bg-surface2 hover:text-ink' }}">
                <x-icon :name="$icon"/> {{ $label }}
            </a>
        @endforeach
    </nav>
    <div class="mt-auto grid gap-2">
        <button type="button" @click="newTx = true" class="btn btn-primary"><x-icon name="plus"/> Nuevo movimiento</button>
        <div class="flex items-center justify-between rounded-xl bg-surface2 p-2 pl-3">
            <div class="min-w-0 text-xs leading-tight"><div class="truncate font-bold">{{ auth()->user()->name }}</div><div class="text-muted">{{ auth()->user()->isOwner() ? 'Administrador' : 'Miembro' }}</div></div>
            <div class="flex">
                <button type="button" @click="toggle()" class="grid size-10 place-items-center rounded-lg hover:bg-border" aria-label="Cambiar tema"><span x-show="!dark"><x-icon name="moon"/></span><span x-show="dark" x-cloak><x-icon name="sun"/></span></button>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="grid size-10 place-items-center rounded-lg hover:bg-border" aria-label="Cerrar sesión"><x-icon name="logout"/></button></form>
            </div>
        </div>
    </div>
</aside>

<div class="lg:pl-64">
    {{-- Barra superior --}}
    <header class="sticky top-0 z-30 border-b border-line bg-bg/90 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-5xl items-center justify-between gap-3 px-4">
            <h1 class="truncate text-lg font-extrabold tracking-tight">{{ $pageTitle }}</h1>
            <div class="flex items-center gap-2">
                @if(ve_mode())
                <form method="POST" action="{{ route('rate.refresh') }}">
                    @csrf
                    <button class="chip card min-h-10 gap-2 px-3 text-xs" title="Actualizar tasa">
                        @if($r)
                            <span class="num">$1 = Bs {{ number_format($r, 2, ',', '.') }}</span>
                        @else
                            <span class="text-warn">Sin tasa</span>
                        @endif
                        <x-icon name="refresh" class="size-4 text-muted"/>
                    </button>
                </form>
                @endif
                <div class="flex lg:hidden">
                    <button type="button" @click="toggle()" class="grid size-10 place-items-center rounded-lg" aria-label="Cambiar tema"><span x-show="!dark"><x-icon name="moon"/></span><span x-show="dark" x-cloak><x-icon name="sun"/></span></button>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="grid size-10 place-items-center rounded-lg" aria-label="Cerrar sesión"><x-icon name="logout"/></button></form>
                </div>
            </div>
        </div>
    </header>

    <main id="main" class="mx-auto max-w-5xl px-4 pb-32 pt-5 lg:pb-12">
        @foreach($shareRequests as $rq)
            <div class="card mb-4 rise border-brand p-4" role="region" aria-label="Solicitud de gastos compartidos">
                <div class="flex items-start gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-brand"><x-icon name="share" class="size-5"/></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-extrabold">{{ $rq->inviter->name }} quiere compartir gastos contigo</p>
                        <p class="text-sm text-muted">Verás solo los gastos que te comparta (cuánto te toca, cuánto pagaste y cuánto falta). No verá nada de tu cuenta.</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('requests.accept', $rq->id) }}">@csrf<button class="btn btn-primary !min-h-11">Aceptar</button></form>
                            <form method="POST" action="{{ route('requests.decline', $rq->id) }}">@csrf<button class="btn btn-ghost !min-h-11">Rechazar</button></form>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
        @if(session('ok'))<div class="card rise mb-4 flex items-center gap-2 border-brand p-3 text-sm font-bold text-brand" role="status"><x-icon name="check"/> {{ session('ok') }}</div>@endif
        @if(session('error'))<div class="card mb-4 flex items-center gap-2 p-3 text-sm font-bold text-warn" role="alert"><x-icon name="alert"/> {{ session('error') }}</div>@endif
        @if($errors->any())<div class="card mb-4 border-expense p-3 text-sm text-expense" role="alert"><b>Revisa los datos:</b> {{ $errors->first() }}</div>@endif
        {{ $slot }}
    </main>
</div>

{{-- Navegación móvil: 4 accesos + "Más" (Metas, Deudas, Familia) --}}
@php
 $mobile = array_values(array_filter($nav, fn ($n) => in_array($n[0], ['dashboard', 'transactions.index', 'market.index', 'budget.index'])));
 $more = array_values(array_filter($nav, fn ($n) => in_array($n[0], ['fixed.index', 'goals.index', 'debts.index', 'reports.index', 'family.index', 'shared.index'])));
 $moreActive = collect($more)->contains(fn ($n) => request()->routeIs($n[0]));
@endphp
<div x-show="more" x-cloak class="fixed inset-0 z-40 bg-black/40 lg:hidden" @click="more = false"></div>
<div x-show="more" x-cloak x-transition class="fixed inset-x-3 bottom-[4.5rem] z-40 card p-2 shadow-xl lg:hidden" role="menu">
    @foreach($more as [$route, $label, $icon])
        <a href="{{ route($route) }}" role="menuitem" class="flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm font-bold hover:bg-surface2"><x-icon :name="$icon" class="text-brand"/> {{ $label }}</a>
    @endforeach
</div>
<nav class="fixed inset-x-0 bottom-0 z-50 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden" aria-label="Principal">
    <div class="mx-auto grid max-w-md grid-cols-5">
        @foreach($mobile as [$route, $label, $icon])
            @php $on = request()->routeIs($route); @endphp
            <a href="{{ route($route) }}" @if($on) aria-current="page" @endif class="flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] font-bold {{ $on ? 'text-brand' : 'text-muted' }}">
                <x-icon :name="$icon" class="size-5"/>{{ $label }}
            </a>
        @endforeach
        <button type="button" @click="more = !more" :aria-expanded="more" class="flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] font-bold {{ $moreActive ? 'text-brand' : 'text-muted' }}">
            <x-icon name="more" class="size-5"/>Más
        </button>
    </div>
</nav>
@unless(request()->routeIs('market.index'))
<button type="button" @click="newTx = true" class="btn btn-primary fixed bottom-20 right-4 z-40 size-14 !rounded-2xl !p-0 shadow-lg lg:hidden" aria-label="Nuevo movimiento"><x-icon name="plus" class="size-6"/></button>
@endunless

<datalist id="all-places">@foreach($placeList as $pl)<option value="{{ $pl }}">@endforeach</datalist>
{{-- Modal: nuevo movimiento --}}
<div x-show="newTx" x-cloak class="fixed inset-0 z-50 grid items-end bg-black/50 sm:place-items-center" @click.self="newTx = false" role="dialog" aria-modal="true" aria-label="Nuevo movimiento">
    <form method="POST" action="{{ route('transactions.store') }}" x-data="moneyForm({{ $r ?? 'null' }})" @open-tx.window="type = $event.detail?.type || 'expense'" class="card max-h-[92dvh] w-full max-w-md overflow-y-auto overscroll-contain rounded-b-none p-5 sm:rounded-b-[1.1rem]" x-transition>
        @csrf
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-extrabold">Nuevo movimiento</h2>
            <button type="button" @click="newTx = false" class="grid size-10 place-items-center rounded-lg hover:bg-surface2" aria-label="Cerrar"><x-icon name="x"/></button>
        </div>
        <div class="mb-4 grid grid-cols-2 gap-2 rounded-xl bg-surface2 p-1" role="radiogroup">
            <label class="cursor-pointer"><input type="radio" name="type" value="expense" x-model="type" class="peer sr-only"><span class="grid min-h-11 place-items-center rounded-lg text-sm font-bold peer-checked:bg-surface peer-checked:text-expense peer-checked:shadow-sm peer-focus-visible:outline">Gasto</span></label>
            <label class="cursor-pointer"><input type="radio" name="type" value="income" x-model="type" class="peer sr-only"><span class="grid min-h-11 place-items-center rounded-lg text-sm font-bold peer-checked:bg-surface peer-checked:text-income peer-checked:shadow-sm peer-focus-visible:outline">Ingreso</span></label>
        </div>
        <div class="grid gap-3">
            <div class="grid grid-cols-[1fr_auto] gap-2">
                <div>
                    <label class="label" for="tx-amount">Monto</label>
                    <input id="tx-amount" name="amount" x-model="amount" class="field num text-xl font-extrabold" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="0.00" required>
                </div>
                <div>
                    <label class="label" for="tx-cur">Moneda</label>
                    <x-currency-select id="tx-cur" model="currency"/>
                </div>
            </div>
            <p class="-mt-1 min-h-5 text-sm font-medium text-muted num" x-text="hint" aria-live="polite"></p>
            <div>
                <label class="label" for="tx-cat">Categoría</label>
                <select id="tx-cat" name="category_id" class="field" x-show="!newCat && type === 'expense'" :disabled="newCat || type !== 'expense'">
                    <option value="">Sin categoría</option>
                    @foreach($cats->where('type','expense')->whereNull('parent_id') as $c)
                        @if($kids->has($c->id))
                            <optgroup label="{{ $c->name }}">
                                <option value="{{ $c->id }}">{{ $c->name }} (general)</option>
                                @foreach($kids[$c->id] as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                            </optgroup>
                        @else
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endif
                    @endforeach
                </select>
                <select name="category_id" class="field" x-show="!newCat && type === 'income'" x-cloak :disabled="newCat || type !== 'income'">
                    <option value="">Sin categoría</option>
                    @foreach($cats->where('type','income')->whereNull('parent_id') as $c)
                        @if($kids->has($c->id))
                            <optgroup label="{{ $c->name }}">
                                <option value="{{ $c->id }}" @selected($c->name === 'Salario')>{{ $c->name }} (general)</option>
                                @foreach($kids[$c->id] as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                            </optgroup>
                        @else
                            <option value="{{ $c->id }}" @selected($c->name === 'Salario')>{{ $c->name }}</option>
                        @endif
                    @endforeach
                </select>
                <input name="new_category" x-show="newCat" x-cloak :disabled="!newCat" class="field" maxlength="40" autocomplete="off"
                       :placeholder="type === 'income' ? 'Ej: Bono, Freelance, Remesas…' : 'Nombre de la categoría'" aria-label="Nombre de la nueva categoría">
                <button type="button" class="mt-1.5 min-h-10 text-sm font-bold text-brand" @click="newCat = !newCat" x-text="newCat ? 'Usar una categoría existente' : '+ Nueva categoría'"></button>
            </div>
            <div x-show="type === 'expense'" x-data="{ newPerson: {{ $peopleList->isEmpty() ? 'true' : 'false' }}, pmail: '' }">
                <span class="label">¿Para quién? <span class="font-medium">(opcional)</span></span>
                @if($peopleList->isNotEmpty())
                <fieldset :disabled="newPerson || type !== 'expense'" class="flex flex-wrap gap-2" x-show="!newPerson">
                    <legend class="sr-only">¿Para quién es este gasto?</legend>
                    <label class="cursor-pointer">
                        <input type="radio" name="person_id" value="" checked class="peer sr-only">
                        <span class="chip min-h-11 border border-line px-3 text-sm font-bold text-muted peer-checked:border-brand peer-checked:bg-brand-soft peer-checked:text-brand peer-focus-visible:outline">Toda la familia</span>
                    </label>
                    @foreach($peopleList as $pp)
                    <label class="cursor-pointer">
                        <input type="radio" name="person_id" value="{{ $pp->id }}" class="peer sr-only">
                        <span class="chip min-h-11 gap-2 border border-line px-3 text-sm font-bold peer-checked:border-brand peer-checked:bg-brand-soft peer-checked:text-brand peer-focus-visible:outline">
                            <span class="size-2.5 rounded-full" style="background:{{ $pp->color }}"></span>{{ $pp->formLabel($meId) }}
                        </span>
                    </label>
                    @endforeach
                </fieldset>
                @endif
                <div x-show="newPerson" x-cloak class="grid gap-2">
                    <input name="new_person" :disabled="!newPerson || type !== 'expense'" class="field" maxlength="60" autocomplete="off" placeholder="Nombre (Ej: María)" aria-label="Nombre de la nueva persona">
                    <input name="new_person_email" type="email" x-model="pmail" :disabled="!newPerson || type !== 'expense'" class="field" maxlength="120" autocomplete="off" placeholder="Correo (opcional)" aria-label="Correo de la nueva persona">
                    <label class="flex items-start gap-2 text-xs" x-show="pmail.trim() !== ''" x-cloak>
                        <input type="checkbox" name="new_person_invite" value="1" class="mt-0.5 size-4" checked :disabled="!newPerson || type !== 'expense'">
                        <span><b>Invitarle</b> a ver los gastos que le compartas. Si ya tiene cuenta, le aparecerá una solicitud en la app.</span>
                    </label>
                </div>
                @if($peopleList->isEmpty())
                    <p class="mt-1.5 text-xs text-muted">Aún no tienes personas en tu lista. Escribe un nombre (hijo, pareja, mascota…) y quedará guardado con este gasto; la próxima vez lo verás aquí. También puedes agregarlas en <a class="font-bold text-brand" href="{{ route('family.index') }}">Familia</a>.</p>
                @else
                    <button type="button" class="mt-1 min-h-10 text-sm font-bold text-brand" @click="newPerson = !newPerson" x-text="newPerson ? 'Elegir de la lista' : '+ Nueva persona'"></button>
                @endif
            </div>
            <fieldset x-show="type === 'expense'" x-cloak :disabled="type !== 'expense'" class="grid gap-1.5">
                <legend class="label">¿Ya lo pagaste?</legend>
                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line bg-surface p-2 text-xs has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                    <input type="radio" name="status" value="pending" class="mt-0.5" checked><span><b>Aún no</b> · lo registro y confirmo el pago después</span>
                </label>
                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line bg-surface p-2 text-xs has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                    <input type="radio" name="status" value="paid" class="mt-0.5"><span><b>Sí, ya está pagado</b> · cuenta de inmediato</span>
                </label>
            </fieldset>
            @if($sharePeople->isNotEmpty())
            <div x-show="type === 'expense'" x-data="{ open: false, rows: [{ person_id: '', percent: '' }], add() { if (this.rows.length < 10) this.rows.push({ person_id: '', percent: '' }) }, total() { return this.rows.reduce((t, r) => t + (parseFloat(r.percent) || 0), 0) }, has() { return this.rows.some(r => r.person_id !== '') } }" class="rounded-xl bg-surface2 p-3">
                <button type="button" class="flex min-h-10 w-full items-center justify-between text-left text-sm font-bold" @click="open = !open" :aria-expanded="open">
                    <span>¿Alguien más paga parte? <span class="font-medium text-muted">(opcional, una o varias personas)</span></span>
                    <x-icon name="chev-right" class="size-4 text-muted transition" x-bind:class="open && 'rotate-90'"/>
                </button>
                <div x-show="open" x-cloak class="mt-2 grid gap-2">
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="grid grid-cols-[1fr_6.5rem_auto] items-center gap-2">
                            <select :name="'shares[' + i + '][person_id]'" x-model="row.person_id" :disabled="!open || type !== 'expense'" class="field" aria-label="Persona que paga parte">
                                <option value="">Elige una persona</option>
                                @foreach($sharePeople as $pp)<option value="{{ $pp->id }}">{{ $pp->name }}</option>@endforeach
                            </select>
                            <div class="relative">
                                <input :name="'shares[' + i + '][percent]'" x-model="row.percent" :disabled="!open || type !== 'expense'" type="number" step="0.01" min="0.01" max="100" inputmode="decimal" class="field num pr-7" placeholder="40" aria-label="Porcentaje que paga">
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm font-bold text-muted">%</span>
                            </div>
                            <button type="button" class="grid size-10 place-items-center rounded-xl text-muted hover:text-expense" @click="rows.length > 1 ? rows.splice(i, 1) : (rows[0] = { person_id: '', percent: '' })" aria-label="Quitar persona">✕</button>
                        </div>
                    </template>
                    <button type="button" class="min-h-10 text-left text-sm font-bold text-brand" @click="add()">+ Agregar otra persona</button>
                    <div class="grid gap-1.5" x-show="has()" x-cloak>
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line bg-surface p-2 text-xs has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                            <input type="radio" name="share_mode" value="split" class="mt-0.5" checked :disabled="!open || !has() || type !== 'expense'"><span><b>Cada quien paga su parte</b> · tu gasto es solo tu <span x-text="Math.max(0, 100 - total()).toFixed(2).replace(/\.?0+$/, '')"></span> %</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line bg-surface p-2 text-xs has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                            <input type="radio" name="share_mode" value="reimburse" class="mt-0.5" :disabled="!open || !has() || type !== 'expense'"><span><b>Yo pago el total</b> · me reembolsan su parte</span>
                        </label>
                        <p class="text-xs text-muted">Verás si pagó o no en Deudas.</p>
                    </div>
                </div>
            </div>
            @endif
            <div>
                <label class="label" for="tx-place" x-text="type === 'income' ? '¿De quién o de dónde viene? (opcional)' : '¿Dónde o para quién? (opcional)'"></label>
                <input id="tx-place" name="place" list="all-places" class="field" maxlength="60" autocomplete="off"
                       :placeholder="type === 'income' ? 'Ej: Empresa XYZ, Carlos…' : 'Ej: Restaurante El Fogón, Colegio de María…'">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="label" for="tx-date">Fecha</label><input id="tx-date" type="date" name="date" class="field" value="{{ now()->toDateString() }}" :max="type === 'expense' ? '{{ now()->addYears(2)->toDateString() }}' : '{{ now()->toDateString() }}'" required>
                    <p class="mt-1 text-xs text-muted" x-show="type === 'expense'" x-cloak>¿Es un gasto a futuro? Pon su fecha y se descuenta en el mes que corresponde.</p></div>
                <div><label class="label" for="tx-note">Nota</label><input id="tx-note" name="note" class="field" maxlength="140" placeholder="Opcional"></div>
            </div>
        </div>
        <div class="sticky bottom-0 -mx-5 -mb-5 mt-4 border-t border-line bg-surface px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3"><button class="btn btn-primary w-full">Guardar</button></div>
    </form>
</div>
</body>
</html>
