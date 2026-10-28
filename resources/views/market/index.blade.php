<x-layouts.app title="Mercado">
@php $rate = rate(); $ve = ve_mode(); @endphp
<div x-data="market({
        user: {{ auth()->id() }}, base: '{{ base_currency() }}', rate: {{ $rate ?? 'null' }},
        fmt: {{ \Illuminate\Support\Js::from($currency) }}, saved: {{ session('market_saved') ? 'true' : 'false' }}
    })" class="min-w-0 pb-28">

    {{-- Paso 1: ¿cómo quieres registrar la compra? --}}
    <section x-show="flow === null" x-cloak class="rise">
        <h2 class="text-xl font-extrabold tracking-tight">¿Cómo quieres registrar tu compra?</h2>
        <p class="mt-1 text-sm text-muted">Puedes cambiar de opción cuando quieras.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <button type="button" @click="flow = 'detail'" class="card group flex min-h-28 items-center gap-4 p-4 text-left transition hover:-translate-y-0.5 hover:border-brand hover:shadow-md">
                <span class="grid size-14 shrink-0 place-items-center rounded-2xl text-white shadow-[0_4px_0_rgba(4,90,66,.5)]" style="background:linear-gradient(135deg,#34d399,#0b8f6a)"><x-icon name="cart" class="size-7"/></span>
                <span class="min-w-0 flex-1 leading-snug">
                    <span class="block text-base font-extrabold">Detallar los productos</span>
                    <span class="block text-sm text-muted">Anota cada producto con su precio y ve el total mientras compras.</span>
                </span>
            </button>
            <button type="button" @click="flow = 'total'" class="card group flex min-h-28 items-center gap-4 p-4 text-left transition hover:-translate-y-0.5 hover:border-info hover:shadow-md">
                <span class="grid size-14 shrink-0 place-items-center rounded-2xl text-white shadow-[0_4px_0_rgba(49,46,129,.45)]" style="background:linear-gradient(135deg,#818cf8,#4f46e5)"><x-icon name="receipt" class="size-7"/></span>
                <span class="min-w-0 flex-1 leading-snug">
                    <span class="block text-base font-extrabold">Solo el total de la factura</span>
                    <span class="block text-sm text-muted">Ya compraste: escribe el monto total y listo.</span>
                </span>
            </button>
        </div>
    </section>

    {{-- Barra de modo --}}
    <div x-show="flow !== null" x-cloak class="mb-3 flex items-center justify-between gap-2 rounded-xl bg-surface2 px-3 py-2 text-sm">
        <span class="flex items-center gap-2 font-bold"><x-icon :name="'cart'" class="size-4 text-brand" x-show="flow === 'detail'"/><x-icon name="receipt" class="size-4 text-info" x-show="flow === 'total'"/>
            <span x-text="flow === 'detail' ? 'Detallando productos' : 'Solo el total'"></span></span>
        <button type="button" class="min-h-10 shrink-0 font-bold text-brand" @click="flow = flow === 'detail' ? 'total' : 'detail'" x-text="flow === 'detail' ? 'Solo el total' : 'Detallar'"></button>
    </div>

    {{-- ===== Modo: solo el total de la factura ===== --}}
    <form x-show="flow === 'total'" x-cloak method="POST" action="{{ route('market.store') }}" x-data="moneyForm({{ $rate ?? 'null' }})" class="card rise grid gap-3 p-4">
        @csrf
        <h2 class="font-extrabold">Total de la factura</h2>
        <div class="grid grid-cols-[1fr_auto] gap-2">
            <div>
                <label class="label" for="t-amount">Total pagado</label>
                <input id="t-amount" name="amount" x-model="amount" type="number" step="0.01" min="0.01" inputmode="decimal" class="field num text-xl font-extrabold" placeholder="0.00" required>
            </div>
            <div><label class="label" for="t-cur">Moneda</label><x-currency-select id="t-cur" model="currency"/></div>
        </div>
        <p class="-mt-1 min-h-5 text-sm font-medium text-muted num" x-text="hint" aria-live="polite"></p>
        <div>
            <label class="label" for="t-place">Mercado o tienda <span class="font-medium">(opcional)</span></label>
            <input id="t-place" name="place" list="all-places" class="field" maxlength="60" autocomplete="off" placeholder="Ej: Mercado municipal, Gama…">
        </div>
        @include('market._category', ['id' => 't-cat'])
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label" for="t-date">Fecha</label><input id="t-date" type="date" name="date" class="field" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
            <div><label class="label" for="t-note">Nota <span class="font-medium">(opcional)</span></label><input id="t-note" name="note" class="field" maxlength="140"></div>
        </div>
        <button class="btn btn-primary mt-1 w-full"><x-icon name="check"/> Guardar gasto</button>
    </form>

    {{-- ===== Modo: detallar productos ===== --}}
    <div x-show="flow === 'detail'" x-cloak>
        <section class="card p-4">
            <label class="label !mb-1" for="m-place">¿Dónde estás comprando? <span class="font-medium">(opcional)</span></label>
            <input id="m-place" x-model="place" list="all-places" class="field" maxlength="60" autocomplete="off" placeholder="Ej: Mercado municipal, Gama…">
        </section>

        <form @submit.prevent="add()" class="card mt-3 p-4" novalidate>
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-extrabold">Agregar producto</h2>
                @if($ve)
                <div class="inline-flex rounded-xl bg-surface2 p-1 text-sm font-bold" role="radiogroup" aria-label="Moneda del precio">
                    <button type="button" role="radio" :aria-checked="mode === 'USD'" @click="mode = 'USD'" :class="mode === 'USD' ? 'bg-surface shadow-sm' : 'text-muted'" class="min-h-10 rounded-lg px-3">USD $</button>
                    <button type="button" role="radio" :aria-checked="mode === 'VES'" @click="mode = 'VES'" :class="mode === 'VES' ? 'bg-surface shadow-sm' : 'text-muted'" class="min-h-10 rounded-lg px-3">Bs</button>
                </div>
                @else
                <span class="chip bg-surface2 text-muted">{{ base_currency() }}</span>
                @endif
            </div>
            <div class="grid grid-cols-[1fr_7.5rem] gap-2">
                <div>
                    <label class="label" for="m-name">Producto <span class="font-medium">(opcional)</span></label>
                    <input id="m-name" x-ref="name" x-model="form.name" list="m-names" class="field" maxlength="60" autocomplete="off" placeholder="Ej: Arroz 1 kg"
                           @keydown.enter.prevent="$refs.price.focus()">
                    <datalist id="m-names"><template x-for="n in names" :key="n"><option :value="n"></option></template></datalist>
                </div>
                <div>
                    <label class="label" for="m-price">Precio</label>
                    <input id="m-price" x-ref="price" x-model="form.price" class="field num text-lg font-extrabold" inputmode="decimal" autocomplete="off" placeholder="0.00">
                </div>
            </div>
            <div class="mt-3 flex items-end gap-3">
                <div>
                    <span class="label">Cantidad</span>
                    <div class="flex items-center overflow-hidden rounded-xl border border-line">
                        <button type="button" class="grid size-11 place-items-center hover:bg-surface2" @click="form.qty = Math.max(1, (parseInt(form.qty) || 1) - 1)" aria-label="Menos"><x-icon name="minus" class="size-4"/></button>
                        <input x-model="form.qty" inputmode="numeric" class="num h-11 w-12 bg-transparent text-center font-extrabold" aria-label="Cantidad">
                        <button type="button" class="grid size-11 place-items-center hover:bg-surface2" @click="form.qty = (parseInt(form.qty) || 1) + 1" aria-label="Más"><x-icon name="plus" class="size-4"/></button>
                    </div>
                </div>
                <button class="btn btn-primary h-11 flex-1"><x-icon name="plus"/> Agregar</button>
            </div>
            <p class="mt-2 text-sm font-bold text-expense" x-show="error" x-text="error" role="alert"></p>
        </form>

        <section class="card mt-3 p-4">
            <label class="label !mb-1" for="m-budget">Presupuesto de esta compra <span class="font-medium">(opcional)</span></label>
            <input id="m-budget" x-model="budget" class="field num" inputmode="decimal" placeholder="¿Cuánto piensas gastar? ({{ base_currency() }})">
            <div x-show="left !== null" x-cloak class="mt-3">
                <div class="h-2.5 overflow-hidden rounded-full bg-surface2" role="progressbar" :aria-valuenow="Math.round(pct)" aria-valuemin="0" aria-valuemax="100" aria-label="Uso del presupuesto de la compra">
                    <div class="h-full rounded-full transition-all" :style="`width:${pct}%;background:${left < 0 ? 'var(--expense)' : (pct > 80 ? 'var(--warn)' : 'var(--brand)')}`"></div>
                </div>
                <p class="num mt-1.5 text-sm font-bold" :class="left < 0 ? 'text-expense' : 'text-muted'" x-text="left < 0 ? 'Te pasaste por ' + fmt(-left) : 'Te quedan ' + fmt(left)"></p>
            </div>
        </section>

        <section class="mt-3">
            <div class="mb-1 flex items-center justify-between px-1">
                <h2 class="font-extrabold">Tu lista <span class="text-sm font-medium text-muted" x-text="items.length ? '(' + count + (count === 1 ? ' unidad)' : ' unidades)') : ''"></span></h2>
                <button type="button" class="min-h-10 text-sm font-bold text-muted hover:text-expense" x-show="items.length" @click="clear()">Vaciar</button>
            </div>
            <p x-show="!items.length" class="card p-6 text-center text-sm text-muted">Aún no hay productos. Escribe el precio y toca <b>Agregar</b>: el total se suma solo.</p>
            <ul class="grid gap-2">
                <template x-for="i in items" :key="i.id">
                    <li class="card min-w-0 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-bold" x-text="i.name || 'Producto'"></p>
                                <p class="num text-xs text-muted"><span x-text="unit(i)"></span> c/u</p>
                            </div>
                            <p class="num shrink-0 text-lg font-extrabold" x-text="fmt(i.qty * i.base)"></p>
                        </div>
                        <div class="mt-2 flex items-center justify-between">
                            <div class="flex items-center overflow-hidden rounded-lg border border-line">
                                <button type="button" class="grid size-10 place-items-center hover:bg-surface2" @click="step(i, -1)" aria-label="Menos"><x-icon name="minus" class="size-4"/></button>
                                <span class="num w-9 text-center text-sm font-extrabold" x-text="i.qty"></span>
                                <button type="button" class="grid size-10 place-items-center hover:bg-surface2" @click="step(i, 1)" aria-label="Más"><x-icon name="plus" class="size-4"/></button>
                            </div>
                            <button type="button" class="flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-bold text-muted hover:text-expense" @click="remove(i)"><x-icon name="trash" class="size-4"/> Quitar</button>
                        </div>
                    </li>
                </template>
            </ul>
        </section>

        {{-- Barra de total (siempre visible) --}}
        <div class="fixed inset-x-0 bottom-[3.6rem] z-40 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur lg:bottom-0 lg:left-64">
            <div class="mx-auto flex max-w-5xl items-center gap-3">
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="text-xs font-bold text-muted">Total aproximado</p>
                    <p class="num text-2xl font-extrabold" x-text="fmt(total)"></p>
                    @if($ve)<p class="num text-xs font-medium text-muted" x-text="ves(total)"></p>@endif
                </div>
                <button type="button" class="btn btn-primary h-12" :disabled="!items.length" :class="!items.length && 'opacity-50'" @click="openSave()"><x-icon name="check"/> Guardar gasto</button>
            </div>
        </div>

        {{-- Confirmar y guardar --}}
        <div x-show="sheet" x-cloak class="fixed inset-0 z-[60] grid items-end bg-black/50 sm:place-items-center" @click.self="sheet = false" @keydown.escape.window="sheet = false" role="dialog" aria-modal="true" aria-label="Guardar compra como gasto">
            <form method="POST" action="{{ route('market.store') }}" class="card max-h-[92dvh] w-full max-w-md overflow-y-auto overscroll-contain rounded-b-none p-5 sm:rounded-b-[1.1rem]" x-transition>
                @csrf
                <input type="hidden" name="items_json" :value="payload()">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold">Guardar compra</h2>
                    <button type="button" @click="sheet = false" class="grid size-10 place-items-center rounded-lg hover:bg-surface2" aria-label="Cerrar"><x-icon name="x"/></button>
                </div>
                <div class="grid gap-3">
                    <div>
                        <label class="label" for="s-amount">Total pagado ({{ base_currency() }})</label>
                        <input id="s-amount" name="amount" x-model="final" type="number" step="0.01" min="0.01" inputmode="decimal" class="field num text-xl font-extrabold" required>
                        <p class="mt-1 text-xs text-muted">Ajústalo al monto real de la factura si es distinto del aproximado.</p>
                    </div>
                    <div>
                        <label class="label" for="s-place">Mercado o tienda <span class="font-medium">(opcional)</span></label>
                        <input id="s-place" name="place" x-model="place" list="all-places" class="field" maxlength="60" autocomplete="off" placeholder="Ej: Mercado municipal, Gama…">
                    </div>
                    @include('market._category', ['id' => 's-cat'])
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label" for="s-date">Fecha</label><input id="s-date" type="date" name="date" class="field" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
                        <div><label class="label" for="s-note">Nota <span class="font-medium">(opcional)</span></label><input id="s-note" name="note" class="field" maxlength="140"></div>
                    </div>
                </div>
                <button class="btn btn-primary mt-5 w-full">Guardar gasto</button>
            </form>
        </div>
    </div>
</div>
</x-layouts.app>
