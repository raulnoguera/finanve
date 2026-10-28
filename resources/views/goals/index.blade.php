<x-layouts.app title="Metas">
@php
 $badge = [
    'done' => ['Completada', 'bg-brand-soft text-brand'],
    'holgada' => ['Muy alcanzable', 'bg-brand-soft text-brand'],
    'ajustada' => ['Alcanzable, ajustada', 'bg-warn/15 text-warn'],
    'no_viable' => ['Plazo poco realista', 'bg-expense/10 text-expense'],
    'sin_capacidad' => ['Sin margen por ahora', 'bg-expense/10 text-expense'],
    'comprometida' => ['Capacidad ocupada', 'bg-warn/15 text-warn'],
    'sin_fecha' => ['Sin fecha', 'bg-surface2 text-muted'],
    'vencida' => ['Plazo vencido', 'bg-expense/10 text-expense'],
 ];
 $meses = fn ($n) => $n.' '.($n === 1 ? 'mes' : 'meses');
@endphp

{{-- Tu capacidad real --}}
<section class="card rise mb-4 p-5" style="background:linear-gradient(135deg,color-mix(in srgb,var(--info) 14%,var(--surface)),var(--surface))">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm font-bold text-muted">Lo que realmente puedes apartar cada mes</p>
            <x-money :usd="$ctx['capacity']" size="text-3xl" :tone="$ctx['capacity'] <= 0 ? 'text-expense' : 'text-ink'"/>
        </div>
        <span class="chip {{ ['alta' => 'bg-brand-soft text-brand', 'media' => 'bg-warn/15 text-warn', 'baja' => 'bg-expense/10 text-expense'][$ctx['confidence']] }}">
            Fiabilidad {{ $ctx['confidence'] }} · {{ $ctx['months'] === 0 ? 'mes en curso' : $meses($ctx['months']).' de datos' }}
        </span>
    </div>
    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div class="rounded-xl bg-surface/70 p-3"><dt class="text-xs font-bold text-muted">Ingresos / mes</dt><dd class="num font-extrabold text-income">{{ money($ctx['income_avg']) }}</dd></div>
        <div class="rounded-xl bg-surface/70 p-3"><dt class="text-xs font-bold text-muted">Gastos / mes</dt><dd class="num font-extrabold text-expense">{{ money($ctx['expense_avg']) }}</dd></div>
        <div class="rounded-xl bg-surface/70 p-3"><dt class="text-xs font-bold text-muted">Te sobra / mes</dt><dd class="num font-extrabold {{ $ctx['surplus_avg'] < 0 ? 'text-expense' : '' }}">{{ money($ctx['surplus_avg']) }}</dd></div>
        <div class="rounded-xl bg-surface/70 p-3"><dt class="text-xs font-bold text-muted">Colchón (20 %)</dt><dd class="num font-extrabold">{{ money($ctx['buffer']) }}</dd></div>
    </dl>
    <p class="mt-3 text-xs text-muted">Usamos el promedio de tus últimos meses y reservamos un 20 % para imprevistos: así los plazos que ves son realistas y no prometen de más.</p>

    <div class="mt-3 rounded-xl bg-surface/70 p-3 text-sm">
        <p class="font-bold">Este mes te queda <span class="num {{ $ctx['remaining_now'] < 0 ? 'text-expense' : 'text-ink' }}">{{ money($ctx['remaining_now']) }}</span></p>
        <p class="num mt-0.5 text-xs text-muted">
            {{ money($ctx['this_income']) }} de ingresos{{ $ctx['this_income_estimated'] ? ' (estimado: aún no registras ingresos este mes)' : '' }}
            − {{ money($ctx['this_expense']) }} gastado
            − {{ money($ctx['this_saved']) }} ya apartado en metas
            − {{ money($ctx['pending_fixed']) }} de gastos fijos por pagar
            @if(($ctx['pending_planned'] ?? 0) > 0) − {{ money($ctx['pending_planned']) }} de otros gastos por pagar @endif
        </p>
    </div>

    @if($ctx['surplus_avg'] <= 0)
        <p class="mt-3 flex items-start gap-2 rounded-xl bg-expense/10 p-3 text-sm font-bold text-expense"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            Con tus datos actuales gastas igual o más de lo que ingresas. Antes de comprometerte con una meta, conviene equilibrar tu mes; abajo verás qué recortar.</p>
    @elseif($overcommitted)
        <p class="mt-3 flex items-start gap-2 rounded-xl bg-warn/15 p-3 text-sm font-bold text-warn"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            Entre todas tus metas con fecha necesitarías {{ money($sumNeeds) }} al mes, pero tu capacidad realista es {{ money($ctx['capacity']) }}. Repartimos esa capacidad entre ellas; mira el plan de cada una.</p>
    @endif
</section>

<div class="grid gap-4 lg:grid-cols-2">
@forelse($goals as $goal)
    @php
        $p = $plans[$goal->id];
        $st = $p['status'];
        $sav = $goal->contributions;
        $money = fn ($v) => money($v);
    @endphp
    <article class="card min-w-0 p-5" x-data="{ open: false, history: false }">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-extrabold">{{ $goal->name }}</h2>
                <p class="text-xs text-muted">Meta {{ money($p['target']) }}@if($goal->deadline) · para el {{ $goal->deadline->translatedFormat('d M Y') }}@endif</p>
            </div>
            <span class="chip shrink-0 {{ $badge[$st][1] }}">{{ $badge[$st][0] }}</span>
        </div>

        <div class="mt-4 flex items-end justify-between">
            <x-money :usd="$p['saved']" size="text-2xl"/>
            <span class="num text-2xl font-extrabold text-info">{{ $p['pct'] }}%</span>
        </div>
        <div class="mt-2 h-3 overflow-hidden rounded-full bg-surface2" role="progressbar" aria-valuenow="{{ $p['pct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance de {{ $goal->name }}">
            <div class="h-full rounded-full bg-info transition-all" style="width:{{ $p['pct'] }}%"></div>
        </div>
        <p class="num mt-1.5 text-sm text-muted">Te faltan <b class="text-ink">{{ money($p['remaining']) }}</b></p>

        @if($st !== 'done')
        {{-- Plan realista --}}
        <div class="mt-4 rounded-xl border border-line bg-surface2/60 p-3.5">
            <p class="text-xs font-extrabold uppercase tracking-wide text-muted">Plan realista</p>
            <p class="mt-1 text-sm leading-relaxed">
                @switch($st)
                    @case('holgada')
                        Necesitas <b class="num">{{ money($p['required']) }}/mes</b> durante {{ $meses($p['months_left']) }}. Eso es el {{ round($p['required'] / max(0.01, $ctx['capacity']) * 100) }} % de lo que puedes apartar con seguridad: vas cómodo. @break
                    @case('ajustada')
                        Necesitas <b class="num">{{ money($p['required']) }}/mes</b> durante {{ $meses($p['months_left']) }}: el {{ round($p['required'] / max(0.01, $ctx['capacity']) * 100) }} % de tu capacidad. Es posible, pero queda poco margen; un imprevisto grande te atrasaría. @break
                    @case('no_viable')
                        Para cumplir la fecha necesitarías <b class="num">{{ money($p['required']) }}/mes</b>, pero con tus números actuales solo puedes destinar unos <b class="num">{{ money($p['allocation']) }}/mes</b>. Más realista: llegarías en <b>{{ $meses($p['eta_months']) }}</b> ({{ $p['eta_date']?->translatedFormat('F Y') }}), no en {{ $meses($p['months_left']) }}. @break
                    @case('sin_capacidad')
                        Hoy no hay un monto seguro para apartar{{ $p['required'] ? ': para la fecha elegida necesitarías '.money($p['required']).'/mes' : '' }}. Primero equilibra tus gastos con tus ingresos (mira los consejos de abajo). @break
                    @case('comprometida')
                        Toda tu capacidad de ahorro ({{ money($ctx['capacity']) }}/mes) ya está asignada a metas con fecha. Esta meta avanzará cuando termines alguna de ellas o aumente lo que puedes apartar. @break
                    @case('sin_fecha')
                        Sin fecha límite, te sugerimos apartar <b class="num">{{ money($p['allocation']) }}/mes</b>: la completarías en <b>{{ $meses($p['eta_months']) }}</b> ({{ $p['eta_date']?->translatedFormat('F Y') }}). @break
                    @case('vencida')
                        La fecha ya pasó. Con <b class="num">{{ money($p['allocation']) }}/mes</b> la completarías en {{ $p['eta_months'] ? $meses($p['eta_months']) : 'un tiempo que depende de tu ahorro' }}. Puedes crear la meta de nuevo con una fecha nueva. @break
                @endswitch
            </p>

            @if($p['this_month'] > 0)
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface p-2.5">
                    <p class="text-sm">Este mes te sugerimos apartar <b class="num text-info">{{ money($p['this_month']) }}</b></p>
                    <button type="button" class="btn btn-primary !min-h-10 !px-3 text-sm" @click="open = true; $nextTick(() => { $refs.amt.value = {{ round($p['this_month'], 2) }}; $refs.amt.dispatchEvent(new Event('input')); $refs.amt.focus(); })">Aportar {{ money($p['this_month']) }}</button>
                </div>
            @elseif($ctx['remaining_now'] <= 0)
                <p class="mt-3 rounded-lg bg-warn/15 p-2.5 text-sm font-bold text-warn">Este mes ya no te queda margen: mejor esperar al próximo mes para aportar.</p>
            @endif
        </div>

        {{-- Plazos posibles --}}
        @if(count($p['scenarios']))
        <details class="mt-3 rounded-xl border border-line p-3 text-sm">
            <summary class="cursor-pointer font-bold">Según el plazo, ¿cuánto al mes?</summary>
            <ul class="mt-2 grid gap-1">
                @foreach($p['scenarios'] as $sc)
                    <li class="num flex items-center justify-between gap-2">
                        <span class="text-muted">En {{ $meses($sc['months']) }}</span>
                        <span class="flex items-center gap-2"><b>{{ money($sc['per_month']) }}/mes</b>
                            <span class="chip {{ $sc['comfortable'] ? 'bg-brand-soft text-brand' : ($sc['ok'] ? 'bg-warn/15 text-warn' : 'bg-expense/10 text-expense') }}">{{ $sc['comfortable'] ? 'cómodo' : ($sc['ok'] ? 'ajustado' : 'no alcanza') }}</span></span>
                    </li>
                @endforeach
            </ul>
        </details>
        @endif

        {{-- Cómo llegar más rápido --}}
        @if(count($p['tips']))
        <div class="mt-3">
            <p class="text-xs font-extrabold uppercase tracking-wide text-muted">Cómo llegar antes</p>
            <ul class="mt-1.5 grid gap-2">
                @foreach($p['tips'] as $tip)
                    <li class="flex items-start gap-2 text-sm leading-snug"><x-icon :name="$tip['icon']" class="mt-0.5 size-4 shrink-0 text-info"/><span>{{ $tip['text'] }}</span></li>
                @endforeach
            </ul>
        </div>
        @endif

        <button type="button" class="btn btn-primary mt-4 w-full" @click="open = !open"><x-icon name="plus"/> Aportar a esta meta</button>
        <form x-show="open" x-cloak x-data="moneyForm({{ rate() ?? 'null' }})" method="POST" action="{{ route('goals.contribute', $goal) }}" class="mt-3 grid gap-2">
            @csrf
            <div class="grid grid-cols-[1fr_auto] gap-2">
                <input class="field num text-lg font-extrabold" x-ref="amt" name="amount" x-model="amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="Monto" required aria-label="Monto del aporte">
                <x-currency-select model="currency"/>
            </div>
            <p class="text-sm text-muted num" x-text="hint"></p>
            <input class="field" name="note" maxlength="140" placeholder="Nota (opcional)" aria-label="Nota del aporte">
            <input type="hidden" name="date" value="{{ now()->toDateString() }}">
            <button class="btn btn-ghost">Guardar aporte</button>
            <p class="text-xs text-muted">Queda en el historial de la meta y en tus movimientos como ahorro (se descuenta de lo que te queda).</p>
        </form>
        @endif

        {{-- Historial de aportes --}}
        <div class="mt-4 border-t border-line pt-3">
            <button type="button" class="flex w-full min-h-10 items-center justify-between text-sm font-bold" @click="history = !history" :aria-expanded="history">
                <span>Aportes registrados <span class="text-muted">({{ $sav->count() }})</span></span>
                <x-icon name="chev-right" class="size-4 text-muted transition" x-bind:class="history && 'rotate-90'"/>
            </button>
            <ul x-show="history" x-cloak class="mt-1 divide-y divide-line text-sm">
                @forelse($sav as $c)
                    <li class="flex items-center gap-3 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="num block font-bold">{{ money($c->amount) }}</span>
                            <span class="block truncate text-xs text-muted">{{ $c->date->translatedFormat('d M Y') }} · {{ explode(' ', $c->user->name)[0] }}@if($c->note) · {{ $c->note }}@endif</span>
                        </span>
                        <form method="POST" action="{{ route('goals.contribution.remove', [$goal, $c]) }}" onsubmit="return confirm('¿Eliminar este aporte? Volverá a contar como disponible.')">@csrf @method('DELETE')
                            <button class="grid size-10 place-items-center rounded-lg text-muted hover:text-expense" aria-label="Eliminar aporte"><x-icon name="trash" class="size-4"/></button>
                        </form>
                    </li>
                @empty
                    <li class="py-3 text-muted">Aún no has aportado a esta meta.</li>
                @endforelse
            </ul>
        </div>

        <form method="POST" action="{{ route('goals.destroy', $goal) }}" class="mt-3 text-right" onsubmit="return confirm('¿Eliminar la meta {{ $goal->name }}? Lo apartado vuelve a contar como disponible.')">@csrf @method('DELETE')
            <button class="text-xs font-bold text-muted hover:text-expense">Eliminar meta</button>
        </form>
    </article>
@empty
    <div class="card col-span-full p-8 text-center">
        <x-icon name="target" class="mx-auto size-10 text-info"/>
        <p class="mt-3 font-extrabold">Aún no tienes metas</p>
        <p class="text-sm text-muted">Un viaje, un carro, un fondo de emergencia… crea la primera abajo y verás un plan realista según tus ingresos.</p>
    </div>
@endforelse
</div>

<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Nueva meta</h2>
    <form method="POST" action="{{ route('goals.store') }}" class="mt-3 grid gap-3 sm:grid-cols-[1.4fr_1fr_1fr_auto]">
        @csrf
        <div><label class="label" for="g-name">Nombre</label><input id="g-name" class="field" name="name" placeholder="Ej: Moto Bera 250cc" maxlength="60" required></div>
        <div><label class="label" for="g-amt">Monto ({{ cur() }})</label><input id="g-amt" class="field num" name="target_amount" type="number" step="0.01" min="1" inputmode="decimal" required></div>
        <div><label class="label" for="g-date">Fecha límite <span class="font-medium">(opcional)</span></label><input id="g-date" class="field" name="deadline" type="date" min="{{ now()->addDay()->toDateString() }}"></div>
        <div class="flex items-end"><button class="btn btn-primary w-full">Crear</button></div>
    </form>
    <p class="mt-3 text-xs text-muted">Tras crearla, te mostramos si el plazo es realista con tus ingresos y gastos, y cuánto conviene apartar cada mes.</p>
</section>
</x-layouts.app>
