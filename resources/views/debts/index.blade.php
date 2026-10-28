<x-layouts.app title="Deudas y préstamos">
<div class="grid gap-4 md:grid-cols-2">
@foreach(['owe' => ['Yo debo', $owe, 'text-expense', 'Pagar'], 'lent' => ['Me deben', $lent, 'text-income', 'Cobrar'], 'shared' => ['Pagos compartidos: lo que falta de los demás', $shared, 'text-brand', 'Registrar pago'], 'scheduled' => ['Cuotas programadas de gastos fijos compartidos', $scheduled, 'text-muted', 'Registrar pago']] as $kind => [$title, $list, $tone, $verb])
    @continue(in_array($kind, ['shared', 'scheduled']) && $list->isEmpty())
    @php $pending = $list->whereNull('settled_at')->sum(fn($d) => $d->remaining()); @endphp
    <section class="card p-5">
        <div class="flex items-center justify-between">
            <h2 class="font-extrabold">{{ $title }}</h2>
            <x-money :usd="$pending" :tone="$tone" size="text-lg" class="items-end"/>
        </div>
        @if($kind === 'scheduled')<p class="mt-1 text-xs text-muted">Aún no marcas pagada la cuota del gasto fijo. Ya se le refleja a la otra persona en su cuenta.</p>@endif
        @if($kind === 'shared')<p class="mt-1 text-xs text-muted">Cada persona paga su parte directamente. Aquí registras (o confirmas) cuándo lo hizo; el gasto se completa cuando todos pagan.</p>@endif
        @forelse($list as $d)
            @php $p = $d->amount > 0 ? min(100, $d->paid() / $d->amount * 100) : 0; $late = $d->due_date && $d->due_date->isPast() && !$d->settled_at; @endphp
            <div class="mt-4 rounded-xl bg-surface2 p-3 {{ $d->settled_at ? 'opacity-60' : '' }}" x-data="{ open: false, det: {{ $errors->has('amount') && old('_debt') == $d->id ? 'true' : 'false' }}, edit: false }">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate font-bold">{{ $d->person }}@if($d->linkedPerson && $d->linkedPerson->name !== $d->person) <span class="text-xs font-medium text-muted">· {{ $d->kind === 'owe' ? 'le debes a' : 'te debe' }} {{ $d->linkedPerson->name }}</span>@endif</p>
                        <p class="truncate text-xs {{ $late ? 'font-bold text-expense' : 'text-muted' }}">
                            @if($d->settled_at) Saldada @elseif($d->due_date) {{ $late ? 'Venció' : 'Vence' }} el {{ $d->due_date->translatedFormat('d M') }} @else Sin fecha @endif
                            @if($d->note) · {{ $d->note }} @endif
                            @if($d->transaction_id && $d->percent) · <b>{{ rtrim(rtrim(number_format($d->percent, 2, '.', ''), '0'), '.') }} % del gasto</b> @endif
                            @if($d->linkedPerson?->account) · <span class="font-bold text-brand">con cuenta</span> @elseif($d->linkedPerson?->member_user_id) · <span class="font-bold text-brand">familia</span> @elseif($d->linkedPerson?->email) · <span class="font-bold text-warn">pendiente de aceptar</span> @endif
                        </p>
                    </div>
                    <div class="text-right"><span class="num block text-sm font-extrabold">{{ usd($d->remaining()) }}</span><span class="num text-xs text-muted">de {{ usd($d->amount) }}</span></div>
                </div>
                @php $pctD = (float) $d->percent; $totalD = $pctD > 0 ? round($d->amount / $pctD * 100, 2) : null; @endphp
                <dl class="mt-2 grid grid-cols-2 gap-1.5 text-xs sm:grid-cols-4">
                    <div class="rounded-lg bg-surface p-2"><dt class="font-bold uppercase tracking-wide text-muted">{{ $totalD ? 'Parte' : 'Monto' }}</dt><dd class="num font-extrabold">{{ usd($d->amount) }}</dd></div>
                    <div class="rounded-lg bg-surface p-2"><dt class="font-bold uppercase tracking-wide text-muted">{{ $totalD ? 'Del gasto' : 'Pagado' }}</dt><dd class="num font-extrabold">{{ $totalD ? usd($totalD).' · '.rtrim(rtrim(number_format($pctD, 2, '.', ''), '0'), '.').' %' : usd($d->paid()) }}</dd></div>
                    <div class="rounded-lg bg-surface p-2"><dt class="font-bold uppercase tracking-wide text-muted">Registrada</dt><dd class="num font-extrabold">{{ $d->created_at->translatedFormat('d M Y') }}</dd></div>
                    <div class="rounded-lg bg-surface p-2"><dt class="font-bold uppercase tracking-wide text-muted">{{ $d->fixed_due ? 'Cuota del' : 'Fecha de pago' }}</dt><dd class="num font-extrabold {{ $late ? 'text-expense' : '' }}">{{ ($d->fixed_due ?? $d->due_date)?->translatedFormat('d M Y') ?? 'Sin fecha' }}</dd></div>
                </dl>
                @php $lastPay = $d->payments->first(); @endphp
                @if($lastPay)
                    <p class="num mt-1 flex flex-wrap items-center gap-x-2 text-xs text-muted">
                        Último pago {{ $lastPay->date->translatedFormat('d M') }}: <b class="text-ink">{{ money($lastPay->amount) }}</b>
                        @if($lastPay->reporter) · reportado por {{ $lastPay->reporter->name }} @endif
                        <form method="POST" action="{{ route('debts.unpay', [$d, $lastPay]) }}" class="inline" onsubmit="return confirm('¿Deshacer este pago?')">@csrf @method('DELETE')<button class="font-bold text-brand hover:underline">Deshacer</button></form>
                    </p>
                @endif
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-border"><div class="h-full rounded-full bg-brand" style="width:{{ $p }}%"></div></div>
                @php $items = $d->items; $itemsSum = $d->detailedTotal(); $undet = $d->undetailed(); @endphp
                <div class="mt-2">
                    <button type="button" class="flex min-h-9 items-center gap-1.5 text-sm font-bold text-info" @click="det = !det" :aria-expanded="det">
                        <x-icon name="list" class="size-4"/>
                        {{ $items->isNotEmpty() ? 'Detalles ('.$items->count().')' : 'Detallar esta deuda' }}
                        <x-icon name="chev-right" class="size-3.5 transition" x-bind:class="det && 'rotate-90'"/>
                    </button>
                    <div x-show="det" x-cloak class="mt-1 rounded-lg bg-surface p-3">
                        @if($items->isNotEmpty())
                            <ul class="divide-y divide-line">
                                @foreach($items as $it)
                                    @php $paidIt = $it->isPaid(); @endphp
                                    <li class="flex items-center gap-2 py-2 text-sm">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-bold {{ $paidIt ? 'text-muted line-through' : '' }}">{{ $it->name }}</span>
                                            <span class="num text-xs text-muted">{{ money($it->amount) }}</span>
                                        </span>
                                        <form method="POST" action="{{ route('debts.items.pay', [$d, $it]) }}">@csrf
                                            <button class="chip min-h-9 px-3 {{ $paidIt ? 'bg-brand-soft text-brand' : 'border border-line text-muted hover:border-brand hover:text-brand' }}" title="{{ $paidIt ? 'Deshacer el pago de este detalle' : 'Marcar este detalle como pagado' }}">
                                                @if($paidIt)<x-icon name="check" class="size-3.5"/> Pagado @else Marcar pagado @endif
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('debts.items.destroy', [$d, $it]) }}" onsubmit="return confirm('¿Quitar el detalle «{{ $it->name }}»?')">@csrf @method('DELETE')
                                            <button class="grid size-9 place-items-center rounded-lg text-muted hover:text-expense" aria-label="Quitar {{ $it->name }}"><x-icon name="x" class="size-4"/></button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="num mt-2 flex flex-wrap justify-between gap-x-3 text-xs text-muted">
                                <span>Detallado: <b class="text-ink">{{ money($itemsSum) }}</b> de {{ money($d->amount) }}</span>
                                @if($undet > 0.004)<span class="font-bold text-warn">Sin detallar: {{ money($undet) }}</span>@else<span class="font-bold text-income">Todo detallado ✓</span>@endif
                            </p>
                        @else
                            <p class="text-xs text-muted">Desglosa el total en partes (por ejemplo enganche, cuotas, seguro) y marca cada una cuando la pagues.</p>
                        @endif

                        <form method="POST" action="{{ route('debts.items.store', $d) }}" x-data="moneyForm({{ rate() ?? 'null' }})" class="mt-3 grid grid-cols-[1fr_auto] gap-2">
                            @csrf
                            <input type="hidden" name="_debt" value="{{ $d->id }}">
                            <input class="field !min-h-10 col-span-2" name="name" maxlength="80" placeholder="Detalle (Ej: Enganche)" aria-label="Nombre del detalle">
                            <input class="field num !min-h-10" name="amount" x-model="amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="Monto" required aria-label="Monto del detalle">
                            <x-currency-select model="currency" class="field !min-h-10 !w-24"/>
                            <button class="btn btn-ghost !min-h-10 col-span-2">Agregar detalle</button>
                        </form>
                        @if($errors->has('amount') && old('_debt') == $d->id)<p class="mt-2 text-sm font-bold text-expense" role="alert">{{ $errors->first('amount') }}</p>@endif
                    </div>
                </div>

                <div class="mt-2 flex items-center justify-between">
                    @unless($d->settled_at)<button type="button" class="text-sm font-bold text-brand" @click="open = !open">{{ $verb }}</button>@else<span></span>@endunless
                    <div class="flex items-center gap-3">
                        <button type="button" class="text-xs font-bold text-muted hover:text-brand" @click="edit = !edit">Editar</button>
                        <form method="POST" action="{{ route('debts.destroy', $d) }}" onsubmit="return confirm('¿Eliminar este registro?')">@csrf @method('DELETE')<button class="text-xs font-bold text-muted hover:text-expense">Eliminar</button></form>
                    </div>
                </div>
                <form x-show="edit" x-cloak method="POST" action="{{ route('debts.update', $d) }}" class="mt-2 grid gap-2 rounded-lg bg-surface p-3 sm:grid-cols-2">
                    @csrf @method('PATCH')
                    <input type="hidden" name="_debt" value="{{ $d->id }}">
                    <div><label class="label" for="ed-p{{ $d->id }}">Nombre</label><input id="ed-p{{ $d->id }}" class="field" name="person" maxlength="60" value="{{ $d->person }}" required></div>
                    <div><label class="label" for="ed-a{{ $d->id }}">Total ({{ cur() }})</label><input id="ed-a{{ $d->id }}" class="field num" name="amount" type="number" step="0.01" min="0.01" value="{{ $d->amount + 0 }}" required></div>
                    <div><label class="label" for="ed-d{{ $d->id }}">Fecha de pago</label><input id="ed-d{{ $d->id }}" class="field" type="date" name="due_date" value="{{ $d->due_date?->toDateString() }}"></div>
                    <div><label class="label" for="ed-n{{ $d->id }}">Nota</label><input id="ed-n{{ $d->id }}" class="field" name="note" maxlength="140" value="{{ $d->note }}"></div>
                    <button class="btn btn-primary sm:col-span-2">Guardar cambios</button>
                </form>
                <form x-show="open" x-cloak x-data="moneyForm({{ rate() ?? 'null' }})" method="POST" action="{{ route('debts.pay', $d) }}" class="mt-2 grid gap-2">
                    @csrf
                    <div class="grid grid-cols-[1fr_auto] gap-2">
                        <input class="field num" name="amount" x-model="amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="Monto" required aria-label="Monto">
                        <x-currency-select model="currency"/>
                    </div>
                    <p class="text-sm text-muted num" x-text="hint"></p>
                    <button class="btn btn-primary">Registrar</button>
                </form>
            </div>
        @empty
            <p class="mt-3 text-sm text-muted">{{ $kind === 'owe' ? 'No debes nada. ¡Excelente!' : 'Nadie te debe por ahora.' }}</p>
        @endforelse
    </section>
@endforeach
</div>

<section class="card mt-4 p-5" x-data="debtForm({{ rate() ?? 'null' }})">
    <h2 class="font-extrabold">Registrar deuda o préstamo</h2>
    <form method="POST" action="{{ route('debts.store') }}" class="mt-3 grid gap-3 sm:grid-cols-2">
        @csrf
        <div class="sm:col-span-2 grid grid-cols-2 gap-2 rounded-xl bg-surface2 p-1">
            <label class="cursor-pointer"><input type="radio" name="kind" value="owe" class="peer sr-only" checked><span class="grid min-h-11 place-items-center rounded-lg text-sm font-bold peer-checked:bg-surface peer-checked:text-expense peer-checked:shadow-sm">Yo debo</span></label>
            <label class="cursor-pointer"><input type="radio" name="kind" value="lent" class="peer sr-only"><span class="grid min-h-11 place-items-center rounded-lg text-sm font-bold peer-checked:bg-surface peer-checked:text-income peer-checked:shadow-sm">Me deben</span></label>
        </div>
        <div x-data="{ pid: '{{ old('person_id') }}' }" class="sm:col-span-2 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="label" for="d-person">Nombre de la deuda <span class="font-medium" x-show="pid !== ''" x-cloak>(opcional)</span></label>
                <input id="d-person" class="field" name="person" maxlength="60" :required="pid === ''" placeholder="Ej: Carro, Préstamo, Banco…" value="{{ old('person') }}">
            </div>
            <div>
                <label class="label" for="d-who">¿Con quién? <span class="font-medium">(opcional)</span></label>
                <select id="d-who" class="field" name="person_id" x-model="pid">
                    <option value="">Escribir solo un nombre / otra persona</option>
                    @foreach($people as $pp)
                        <option value="{{ $pp->id }}">{{ $pp->name }}{{ $pp->member_user_id ? ' · miembro de la familia' : ($pp->user_id ? ' · con cuenta' : '') }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2" x-show="pid === ''" x-cloak>
                <label class="label" for="d-mail">Correo de quien tiene cuenta en Finanve <span class="font-medium">(opcional)</span></label>
                <input id="d-mail" class="field" type="email" name="counterparty_email" maxlength="120" placeholder="Para que vea la deuda en su cuenta" value="{{ old('counterparty_email') }}">
                <p class="mt-1 text-xs text-muted">Si ya está en Finanve, le aparecerá una solicitud para aceptar; si no, le llega una invitación. Verá solo esta deuda.</p>
            </div>
        </div>
        <div class="grid grid-cols-[1fr_auto] gap-2">
            <div><label class="label" for="d-amt">Total <span class="font-medium" x-show="rows.length" x-cloak>(opcional: suma de detalles)</span></label>
                <input id="d-amt" class="field num" name="amount" x-model="amount" type="number" step="0.01" min="0.01" inputmode="decimal" :required="!rows.length" :placeholder="rows.length ? detailSum.toFixed(2) : ''"></div>
            <div><label class="label" for="d-cur">Moneda</label><x-currency-select id="d-cur" model="currency"/></div>
        </div>
        @error('amount')<p class="text-sm font-bold text-expense sm:col-span-2" role="alert">{{ $message }}</p>@enderror
        <p class="-mt-1 text-sm text-muted num sm:col-span-2" x-text="hint"></p>

        {{-- Detalles opcionales --}}
        <div class="sm:col-span-2 rounded-xl border border-line p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-bold">Detalles <span class="font-medium text-muted">(opcional)</span></p>
                <button type="button" class="btn btn-ghost !min-h-10 !px-3 text-sm" @click="addRow()"><x-icon name="plus" class="size-4"/> Agregar detalle</button>
            </div>
            <p class="mt-1 text-xs text-muted" x-show="!rows.length">Si el total se compone de varias partes (por ejemplo enganche, cuotas, seguro), desglósalas aquí.</p>
            <div class="mt-2 grid gap-2">
                <template x-for="(r, i) in rows" :key="i">
                    <div class="grid grid-cols-[1fr_7rem_auto] gap-2">
                        <input class="field !min-h-10" :name="'items[' + i + '][name]'" x-model="r.name" maxlength="80" placeholder="Ej: Enganche" aria-label="Nombre del detalle">
                        <input class="field num !min-h-10" :name="'items[' + i + '][amount]'" x-model="r.amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="Monto" aria-label="Monto del detalle">
                        <button type="button" class="grid size-10 place-items-center rounded-lg text-muted hover:text-expense" @click="removeRow(i)" aria-label="Quitar detalle"><x-icon name="x" class="size-4"/></button>
                    </div>
                </template>
            </div>
            <p class="num mt-2 text-sm" x-show="rows.length" x-cloak>
                Suman <b x-text="detailSum.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b>
                <span class="text-muted" x-show="parseFloat(amount) > 0" x-text="'de ' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2})"></span>
                <span class="font-bold text-expense" x-show="parseFloat(amount) > 0 && detailSum > parseFloat(amount) + 0.009"> · superan el total</span>
            </p>
        </div>

        <div><label class="label" for="d-due">Fecha de pago <span class="font-medium">(opcional)</span></label><input id="d-due" class="field" type="date" name="due_date"></div>
        <div><label class="label" for="d-note">Nota <span class="font-medium">(opcional)</span></label><input id="d-note" class="field" name="note" maxlength="140"></div>
        <button class="btn btn-primary sm:col-span-2">Guardar</button>
    </form>
</section>

@if($history->isNotEmpty() || $timeline->isNotEmpty())
<section class="card mt-4 p-5" aria-labelledby="dh-h">
    <h2 id="dh-h" class="font-extrabold">Historial</h2>
    <p class="text-sm text-muted">Deudas y cobros ya saldados, y los pagos registrados (del más reciente al más antiguo).</p>

    @if($history->isNotEmpty())
    <h3 class="mt-4 text-xs font-bold uppercase tracking-wide text-muted">Saldadas ({{ $history->count() }})</h3>
    <ul class="mt-1 divide-y divide-line">
        @foreach($history as $d)
            @php $hl = $d->payments->first(); $kl = ['owe' => 'Yo debía', 'lent' => 'Me debían', 'shared' => 'Compartido']; @endphp
            <li class="flex items-start justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="truncate font-bold">{{ $d->person }} <span class="text-xs font-medium text-muted">· {{ $kl[$d->kind] ?? '' }}</span></p>
                    <p class="text-xs text-muted">
                        @if((float) $d->percent > 0){{ rtrim(rtrim(number_format((float) $d->percent, 2, '.', ''), '0'), '.') }} % · @endif
                        registrada el {{ $d->created_at->translatedFormat('d M Y') }} · saldada el {{ $d->settled_at->translatedFormat('d M Y') }}
                        @if($d->note) · {{ $d->note }}@endif
                    </p>
                    @if($hl)
                    <form method="POST" action="{{ route('debts.unpay', [$d, $hl]) }}" class="mt-0.5" onsubmit="return confirm('¿Deshacer el último pago y reabrir esta deuda?')">@csrf @method('DELETE')
                        <button class="text-xs font-bold text-muted hover:text-expense">Reabrir (deshacer último pago)</button>
                    </form>
                    @endif
                </div>
                <b class="num shrink-0">{{ usd($d->amount) }}</b>
            </li>
        @endforeach
    </ul>
    @endif

    @if($timeline->isNotEmpty())
    <h3 class="mt-5 text-xs font-bold uppercase tracking-wide text-muted">Pagos</h3>
    <ul class="mt-1 divide-y divide-line">
        @foreach($timeline as $x)
            @php $td = $x['debt']; $tp = $x['pay']; @endphp
            <li class="flex items-start justify-between gap-3 py-2.5 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-bold">{{ $td->person }}</p>
                    <p class="text-xs text-muted">{{ $tp->date->translatedFormat('d M Y') }} · {{ $td->kind === 'owe' ? 'pagaste' : 'recibiste' }}@if($tp->reporter) · reportado por {{ $tp->reporter->name }}@endif @if($tp->note) · {{ $tp->note }}@endif</p>
                </div>
                <b class="num shrink-0 {{ $td->kind === 'owe' ? '' : 'text-income' }}">{{ money($tp->amount) }}</b>
            </li>
        @endforeach
    </ul>
    @endif
</section>
@endif
</x-layouts.app>
