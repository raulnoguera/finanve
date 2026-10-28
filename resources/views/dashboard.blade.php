<x-layouts.app title="Inicio">
@php $d = $summary['balance'] - $prev['balance']; @endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-sm text-muted">Hola, {{ explode(' ', auth()->user()->name)[0] }} 👋</p>
        <p class="text-xl font-extrabold tracking-tight">Así va {{ mes_es($month) }}</p>
    </div>
    <x-month-nav :month="$month"/>
</div>

@if($sharedPending->isNotEmpty())
<a href="{{ route('shared.index') }}" class="card mb-4 flex items-center gap-3 border-warn p-4 transition hover:-translate-y-0.5 hover:shadow-md">
    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-warn/15 text-warn"><x-icon name="share" class="size-6"/></span>
    <span class="min-w-0 flex-1 leading-snug"><span class="block font-extrabold">Gastos compartidos contigo</span><span class="num block text-sm text-muted">Te falta pagar <b class="text-ink">{{ $sharedPending->join(' + ') }}</b></span></span>
    <x-icon name="chev-right" class="size-5 text-muted"/>
</a>
@endif

{{-- Carrusel del inicio: cuatro diapositivas con lo más útil del momento --}}
@php
 // La meta más urgente (fecha más cercana); si ninguna tiene fecha, la de mayor avance
 $topGoal = $goals->filter(fn ($g) => $g['goal']->deadline)->sortBy(fn ($g) => $g['goal']->deadline)->first()
     ?? $goals->sortByDesc('pct')->first();
 $slides = 4;
 $cta = 'mt-4 inline-flex min-h-11 items-center gap-2 rounded-full bg-white px-5 text-sm font-extrabold shadow-[0_4px_0_rgba(0,0,0,.25)] transition group-hover:translate-x-0.5';
@endphp
<section class="mb-5" x-data="slides({{ $slides }})" @mouseenter="paused = true" @mouseleave="paused = false" @focusin="paused = true" @focusout="paused = false"
         aria-roledescription="carrusel" aria-label="Accesos rápidos del mes">
    <div x-ref="track" class="hero-track flex snap-x snap-mandatory overflow-x-auto scroll-smooth pb-1" tabindex="0" aria-live="off">

        {{-- 1) ¿Ya pagaste tus gastos fijos? --}}
        <div class="min-w-full snap-center px-1.5" role="group" aria-roledescription="diapositiva" aria-label="1 de {{ $slides }}">
            <div class="tilt3d is-indigo is-hero h-full" x-data="tilt()" @pointermove="move($event)" @pointerleave="reset()">
                <a x-ref="card" href="{{ route('fixed.index', ['mes' => $month->format('Y-m')]) }}" class="card3d group h-full min-h-[13.5rem] gap-2 p-5 sm:p-6">
                    <div class="pop-2 min-w-0 flex-1">
                        <span class="chip bg-white/20 text-white">Gastos fijos</span>
                        <h2 class="mt-2 text-2xl font-extrabold leading-tight tracking-tight sm:text-3xl">¿Ya pagaste tus gastos fijos?</h2>
                        <p class="num mt-1 max-w-sm text-sm font-medium text-white/90">
                            @if($fixedTotals['count'] === 0)
                                Cárgalos una vez (alquiler, servicios, colegio) y cada mes solo los marcas como pagados.
                            @elseif($fixedTotals['unpaid_count'] === 0)
                                ¡Sí! Los {{ $fixedTotals['count'] }} de {{ mes_es($month) }} están pagados ✓ · {{ money($fixedTotals['paid']) }}
                            @else
                                Te {{ $fixedTotals['unpaid_count'] === 1 ? 'falta' : 'faltan' }} <b>{{ $fixedTotals['unpaid_count'] }}</b> por pagar · {{ money($fixedTotals['pending']) }}@if($fixedTotals['overdue_count']) · <b>{{ $fixedTotals['overdue_count'] }} {{ $fixedTotals['overdue_count'] === 1 ? 'vencido' : 'vencidos' }}</b>@endif
                            @endif
                        </p>
                        <span class="{{ $cta }} text-[#3f3bb0]">{{ $fixedTotals['count'] === 0 ? 'Agregar gastos fijos' : 'Ver gastos fijos' }} <x-icon name="chev-right" class="size-4"/></span>
                    </div>
                    <span class="pop bob grid size-28 shrink-0 place-items-center rounded-[1.75rem] bg-white/20 text-white ring-1 ring-white/30 sm:size-32"><x-icon name="repeat" class="size-14"/></span>
                </a>
            </div>
        </div>

        {{-- 2) ¿Vas al mercado? --}}
        <div class="min-w-full snap-center px-1.5" role="group" aria-roledescription="diapositiva" aria-label="2 de {{ $slides }}">
            <div class="tilt3d h-full" x-data="tilt()" @pointermove="move($event)" @pointerleave="reset()">
                <a x-ref="card" href="{{ route('market.index') }}" class="card3d group h-full min-h-[13.5rem] gap-2 p-5 sm:p-6" aria-label="Ir a la calculadora de mercado">
                    <div class="pop-2 min-w-0 flex-1">
                        <span class="chip bg-white/20 text-white">Herramienta</span>
                        <h2 class="mt-2 text-2xl font-extrabold leading-tight tracking-tight sm:text-3xl">¿Vas al mercado?</h2>
                        <p class="mt-1 max-w-xs text-sm font-medium text-white/85">Suma tu compra mientras la haces y guárdala como gasto con un toque.</p>
                        <span class="{{ $cta }} text-[#065f46]">Empezar compra <x-icon name="chev-right" class="size-4"/></span>
                    </div>
                    <svg class="pop h-32 w-36 shrink-0 drop-shadow-[0_14px_10px_rgba(5,75,58,.45)] sm:h-40 sm:w-44" viewBox="0 0 140 112" aria-hidden="true">
            <defs>
                <linearGradient id="bk" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#bff3df"/></linearGradient>
                <radialGradient id="ap" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#ff8a8a"/><stop offset="1" stop-color="#dc2626"/></radialGradient>
                <radialGradient id="cn" cx=".35" cy=".3" r=".9"><stop offset="0" stop-color="#fff3b0"/><stop offset="1" stop-color="#f5b81c"/></radialGradient>
            </defs>
            <ellipse cx="72" cy="104" rx="42" ry="5" fill="#032e23" opacity=".28"/>
            {{-- productos asomando --}}
            <g class="bob" style="--r:-6deg"><rect x="36" y="18" width="22" height="34" rx="5" fill="#fff"/><rect x="36" y="28" width="22" height="9" fill="#3b82f6"/><path d="M36 18 l6 -8 h10 l6 8z" fill="#dbeafe"/></g>
            <g class="bob d2"><circle cx="74" cy="38" r="15" fill="url(#ap)"/><path d="M74 24 q2 -9 10 -10 q-1 8 -10 10z" fill="#22c55e"/><path d="M74 24 v-5" stroke="#7c2d12" stroke-width="2.5" stroke-linecap="round"/></g>
            <g class="bob d3" style="--r:8deg"><circle cx="106" cy="40" r="12" fill="url(#cn)" stroke="#d99a0b" stroke-width="2"/><circle cx="106" cy="40" r="6.5" fill="none" stroke="#d99a0b" stroke-width="2" opacity=".7"/></g>
            {{-- carrito --}}
            <path d="M28 50 H122 L110 88 H42 Z" fill="url(#bk)" stroke="#e6fff5" stroke-width="2" stroke-linejoin="round"/>
            <path d="M52 56 L56 84 M72 56 V84 M92 56 L88 84 M34 66 H116" stroke="#0b9d73" stroke-width="2.2" opacity=".35" stroke-linecap="round"/>
            <path d="M8 24 H24 L36 88 H112" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="50" cy="98" r="8" fill="#063d30"/><circle cx="50" cy="98" r="3" fill="#bff3df"/>
            <circle cx="98" cy="98" r="8" fill="#063d30"/><circle cx="98" cy="98" r="3" fill="#bff3df"/>
        </svg>
                </a>
            </div>
        </div>

        {{-- 3) ¿Quieres crear una meta? --}}
        <div class="min-w-full snap-center px-1.5" role="group" aria-roledescription="diapositiva" aria-label="3 de {{ $slides }}">
            <div class="tilt3d is-amber h-full" x-data="tilt()" @pointermove="move($event)" @pointerleave="reset()">
                <a x-ref="card" href="{{ route('goals.index') }}" class="card3d group h-full min-h-[13.5rem] gap-2 p-5 sm:p-6">
                    <div class="pop-2 min-w-0 flex-1">
                        <span class="chip bg-white/20 text-white">Metas</span>
                        @if($topGoal)
                            <h2 class="mt-2 text-2xl font-extrabold leading-tight tracking-tight sm:text-3xl">Tu meta «{{ \Illuminate\Support\Str::limit($topGoal['goal']->name, 28) }}» va al {{ $topGoal['pct'] }} %</h2>
                            <p class="num mt-1 max-w-sm text-sm font-medium text-white/90">Te faltan {{ money($topGoal['remaining']) }}. @if($topGoal['required']) Necesitas {{ money($topGoal['required']) }}/mes para llegar a tiempo. @endif Mira tu plan realista.</p>
                            <span class="{{ $cta }} text-[#9a4a06]">Ver mis metas <x-icon name="chev-right" class="size-4"/></span>
                        @else
                            <h2 class="mt-2 text-2xl font-extrabold leading-tight tracking-tight sm:text-3xl">{{ $goalsCount ? '¡Cumpliste tus metas! ¿Creamos otra?' : '¿Quieres crear una meta?' }}</h2>
                            <p class="mt-1 max-w-sm text-sm font-medium text-white/90">Un viaje, un carro, un fondo de emergencia… te decimos si es realista con tus ingresos y cuánto conviene apartar al mes.</p>
                            <span class="{{ $cta }} text-[#9a4a06]">Crear una meta <x-icon name="chev-right" class="size-4"/></span>
                        @endif
                    </div>
                    <span class="pop bob grid size-28 shrink-0 place-items-center rounded-[1.75rem] bg-white/20 text-white ring-1 ring-white/30 sm:size-32"><x-icon name="target" class="size-14"/></span>
                </a>
            </div>
        </div>

        {{-- 4) ¿Cuánto gastaste el mes pasado? --}}
        <div class="min-w-full snap-center px-1.5" role="group" aria-roledescription="diapositiva" aria-label="4 de {{ $slides }}">
            <div class="tilt3d is-sky h-full" x-data="tilt()" @pointermove="move($event)" @pointerleave="reset()">
                <a x-ref="card" href="{{ route('reports.index', ['mes' => $lastMonth->format('Y-m')]) }}" class="card3d group h-full min-h-[13.5rem] gap-2 p-5 sm:p-6">
                    <div class="pop-2 min-w-0 flex-1">
                        <span class="chip bg-white/20 text-white">Resumen</span>
                        <h2 class="mt-2 text-2xl font-extrabold leading-tight tracking-tight sm:text-3xl">¿Cuánto gastaste el mes pasado?</h2>
                        <p class="num mt-1 max-w-sm text-sm font-medium text-white/90">
                            @if($prev['expense'] > 0 || $prev['income'] > 0)
                                En {{ $lastMonthLabel }} gastaste <b>{{ money($prev['expense']) }}</b> y te quedaron {{ money($prev['balance']) }}. Este mes llevas {{ money($summary['expense']) }}.
                            @else
                                Aún no hay movimientos de {{ $lastMonthLabel }}. Cuando registres gastos verás aquí cómo vas contra el mes anterior.
                            @endif
                        </p>
                        <span class="{{ $cta }} text-[#0a6b92]">Ver el reporte <x-icon name="chev-right" class="size-4"/></span>
                    </div>
                    <span class="pop bob grid size-28 shrink-0 place-items-center rounded-[1.75rem] bg-white/20 text-white ring-1 ring-white/30 sm:size-32"><x-icon name="chart" class="size-14"/></span>
                </a>
            </div>
        </div>
    </div>

    {{-- Controles: flechas, puntos y pausa del avance automático --}}
    <div class="mt-1 flex items-center justify-center gap-1">
        <button type="button" class="grid size-10 place-items-center rounded-full text-muted hover:bg-surface2" @click="prev()" aria-label="Diapositiva anterior"><x-icon name="chev-left" class="size-5"/></button>
        <div class="flex items-center" role="tablist" aria-label="Elegir diapositiva">
            @for($d = 0; $d < $slides; $d++)
                <button type="button" role="tab" class="grid size-8 place-items-center" @click="go({{ $d }})" :aria-selected="i === {{ $d }}" aria-label="Ir a la diapositiva {{ $d + 1 }}">
                    <span class="block h-2 rounded-full transition-all" :class="i === {{ $d }} ? 'w-6 bg-brand' : 'w-2 bg-line'"></span>
                </button>
            @endfor
        </div>
        <button type="button" class="grid size-10 place-items-center rounded-full text-muted hover:bg-surface2" @click="next()" aria-label="Diapositiva siguiente"><x-icon name="chev-right" class="size-5"/></button>
        <button type="button" class="ml-1 grid size-10 place-items-center rounded-full text-muted hover:bg-surface2" @click="toggle()" :aria-label="playing ? 'Pausar avance automático' : 'Reanudar avance automático'">
            <span x-show="playing"><x-icon name="minus" class="size-5"/></span>
            <span x-show="!playing" x-cloak><x-icon name="refresh" class="size-5"/></span>
        </button>
    </div>
</section>

{{-- ¿Ya ingresaste tu presupuesto? --}}
<section class="card rise mb-4 p-5" aria-labelledby="bud-q">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 id="bud-q" class="font-extrabold">¿Ya ingresaste tu presupuesto?</h2>
            <p class="num mt-0.5 text-sm text-muted">
                @if($hasBudget)
                    Sí ✓ · {{ $budgets->count() }} {{ $budgets->count() === 1 ? 'categoría' : 'categorías' }} con tope · {{ money($budgetTotal) }} al mes
                @else
                    Aún no. Fija cuánto quieres gastar por categoría y te avisamos antes de pasarte.
                @endif
            </p>
        </div>
        <a href="{{ route('budget.index', ['mes' => $month->format('Y-m')]) }}" class="btn {{ $hasBudget ? 'btn-ghost' : 'btn-primary' }} min-h-11">{{ $hasBudget ? 'Ajustar presupuesto' : 'Ingresar presupuesto' }}</a>
    </div>
</section>

{{-- Saldo que tienes vs. saldo después de lo que aún falta por pagar --}}
<section class="card rise mb-5 p-5" aria-labelledby="fc-h">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="fc-h" class="font-extrabold">Saldo de {{ mes_es($month) }}</h2>
        <button type="button" class="text-sm font-bold text-brand" @click="$dispatch('open-tx', { type: 'expense' })">+ Registrar gasto a futuro</button>
    </div>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-surface2 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-muted">Saldo que tienes</p>
            <x-money :usd="$forecast['balance']" size="text-3xl" class="mt-1"/>
            <p class="mt-1 text-xs text-muted">Ingresos − gastos ya pagados − metas.</p>
        </div>
        <div class="rounded-xl p-4 {{ $forecast['projected'] < 0 ? 'bg-expense/10' : 'bg-brand-soft' }}">
            <p class="text-xs font-bold uppercase tracking-wide text-muted">Saldo restando lo que falta pagar</p>
            <x-money :usd="$forecast['projected']" size="text-3xl" class="mt-1"/>
            <p class="mt-1 text-xs text-muted">Descuenta gastos fijos sin pagar y gastos por pagar de este mes.</p>
        </div>
    </div>

    <dl class="num mt-4 grid gap-1 text-sm">
        <div class="flex justify-between gap-3"><dt class="text-muted">Gastos fijos por pagar (tu parte)</dt><dd class="font-extrabold">{{ money($forecast['fixed_pending']) }}</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-muted">Otros gastos por pagar ({{ $forecast['planned_items']->count() }})</dt><dd class="font-extrabold">{{ money($forecast['planned']) }}</dd></div>
        <div class="flex justify-between gap-3 border-t border-line pt-1"><dt class="font-bold">Total comprometido del mes</dt><dd class="font-extrabold text-expense">{{ money($forecast['committed']) }}</dd></div>
    </dl>

    @if($ahead['total'] > 0)
        <div class="mt-4 rounded-xl border border-line p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-bold">Presupuesto por gastar <span class="font-medium text-muted">(lo que aún se viene)</span></p>
                <b class="num text-expense">{{ money($ahead['total']) }}</b>
            </div>
            <ul class="mt-2 grid gap-1 text-sm">
                @foreach($ahead['items']->take(6) as $it)
                    <li class="num flex items-center justify-between gap-3">
                        <span class="min-w-0 truncate">{{ $it['name'] }} <span class="text-xs text-muted">· {{ money($it['spent']) }} de {{ money($it['budget']) }}</span></span>
                        <b>{{ money($it['left']) }}</b>
                    </li>
                @endforeach
            </ul>
            <p class="num mt-2 border-t border-line pt-2 text-sm">Si gastas todo tu presupuesto, te quedarían <b class="{{ ($forecast['projected'] - $ahead['total']) < 0 ? 'text-expense' : 'text-ink' }}">{{ money($forecast['projected'] - $ahead['total']) }}</b></p>
            <p class="mt-1 text-xs text-muted">No es un gasto registrado: es lo que dejaste presupuestado para gasolina, mercado, etc. Al registrar cada compra, esto baja solo.</p>
        </div>
    @endif

    @if($forecast['planned_items']->isNotEmpty())
        <ul class="mt-3 divide-y divide-line rounded-xl bg-surface2 px-3 text-sm">
            @foreach($forecast['planned_items']->take(5) as $pi)
                <li class="flex items-center justify-between gap-3 py-2">
                    <span class="min-w-0 truncate"><b>{{ $pi->note ?: ($pi->category?->fullName() ?? 'Gasto') }}</b> <span class="text-xs text-muted">· {{ $pi->date->translatedFormat('d M') }}</span></span>
                    <span class="num font-extrabold">{{ money($pi->amount) }}</span>
                </li>
            @endforeach
        </ul>
        @if($forecast['planned_items']->count() > 5)<a class="mt-1 inline-block text-xs font-bold text-brand" href="{{ route('transactions.index') }}">Ver los {{ $forecast['planned_items']->count() }} en Movimientos</a>@endif
    @endif

    @if($forecast['later']->isNotEmpty())
        <p class="mt-3 text-xs text-muted">Además tienes <b class="text-ink">{{ $forecast['later']->count() }}</b> {{ $forecast['later']->count() === 1 ? 'gasto programado' : 'gastos programados' }} para meses siguientes ({{ money($forecast['later_total']) }}): se descontarán en el mes que les corresponde.</p>
    @endif
</section>

{{-- Accesos: ingresos y gastos fijos (tarjetas 3D) --}}
<div class="mb-5 grid gap-x-4 gap-y-2 sm:grid-cols-2">
    <div class="tilt3d tilt3d-sm rise" x-data="tilt()" @pointermove="move($event)" @pointerleave="reset()">
        <button type="button" x-ref="card" @click="$dispatch('open-tx', { type: 'income' })" class="card3d group w-full gap-4 p-4 text-left">
            <span class="pop-2 grid size-14 shrink-0 place-items-center rounded-2xl bg-white/20 text-white ring-1 ring-white/30"><x-icon name="arrow-down" class="size-7"/></span>
            <span class="pop-2 min-w-0 flex-1 leading-snug">
                <span class="block text-base font-extrabold">Ingresa tu salario e ingresos</span>
                <span class="num block text-sm font-medium text-white/85">
                    @if($summary['income'] > 0) Este mes llevas <b class="text-white">{{ money($summary['income']) }}</b> · agregar otro
                    @else Sueldo, bonos u otros ingresos del mes @endif
                </span>
            </span>
            <span class="pop grid size-9 shrink-0 place-items-center rounded-full bg-white text-[#065f46] shadow-[0_3px_0_rgba(5,75,58,.4)]"><x-icon name="plus" class="size-5"/></span>
        </button>
    </div>
    <div class="tilt3d tilt3d-sm is-indigo rise" x-data="tilt()" @pointermove="move($event)" @pointerleave="reset()">
        <a x-ref="card" href="{{ route('fixed.index', ['mes' => $month->format('Y-m')]) }}" class="card3d group gap-4 p-4">
            <span class="pop-2 grid size-14 shrink-0 place-items-center rounded-2xl bg-white/20 text-white ring-1 ring-white/30"><x-icon name="repeat" class="size-7"/></span>
            <span class="pop-2 min-w-0 flex-1 leading-snug">
                <span class="block text-base font-extrabold">Tus gastos fijos del mes</span>
                <span class="num block text-sm font-medium text-white/85">
                    @if($fixedTotals['count'] === 0) Alquiler, servicios, colegio… cárgalos una vez
                    @else <b class="text-white">{{ $fixedTotals['paid_count'] }} de {{ $fixedTotals['count'] }}</b> {{ $fixedTotals['paid_count'] === 1 ? 'pagado' : 'pagados' }}
                        @if($fixedTotals['unpaid_count']) · faltan {{ money($fixedTotals['pending']) }} @else · ¡todo al día! @endif
                    @endif
                </span>
            </span>
            <span class="pop grid size-9 shrink-0 place-items-center rounded-full bg-white text-[#3f3bb0] shadow-[0_3px_0_rgba(43,39,128,.4)]"><x-icon name="chev-right" class="size-5"/></span>
        </a>
    </div>
</div>

{{-- Balance --}}
<section class="card rise relative overflow-hidden p-5" style="background:linear-gradient(135deg,var(--brand-soft),var(--surface))">
    <p class="text-sm font-bold text-muted">Balance del mes</p>
    <div class="mt-1 flex flex-wrap items-end gap-x-4 gap-y-1">
        <x-money :usd="$summary['balance']" size="text-4xl" :tone="$summary['balance'] < 0 ? 'text-expense' : 'text-ink'"/>
        @if($summary['savings_rate'] !== null)
            @if(($summary['saved'] ?? 0) > 0)
                <a href="{{ route('goals.index') }}" class="chip mb-1 bg-info/10 text-info"><x-icon name="target" class="size-3.5"/> {{ money($summary['saved']) }} apartado para metas</a>
            @endif
            <span class="chip mb-1 {{ $summary['balance'] >= 0 ? 'bg-brand-soft text-brand' : 'bg-expense/10 text-expense' }}">
                <x-icon :name="$summary['balance'] >= 0 ? 'arrow-up' : 'arrow-down'" class="size-3.5"/> Ahorras el {{ $summary['savings_rate'] }}% de tus ingresos
            </span>
        @endif
    </div>
    @if($forecast['committed'] > 0)
        <p class="num mt-2 text-sm">Restando lo que falta por pagar ({{ money($forecast['committed']) }}): <b class="{{ $forecast['projected'] < 0 ? 'text-expense' : 'text-ink' }}">{{ money($forecast['projected']) }}</b></p>
    @endif
    <div class="mt-5 grid grid-cols-2 gap-3">
        <div class="min-w-0 rounded-xl bg-surface/70 p-3">
            <p class="flex items-center gap-1.5 text-xs font-bold text-muted"><span class="grid size-6 place-items-center rounded-full bg-income/15 text-income"><x-icon name="arrow-down" class="size-3.5"/></span> Ingresos</p>
            <x-money :usd="$summary['income']" tone="text-income" size="text-base sm:text-lg" class="mt-1"/>
        </div>
        <div class="min-w-0 rounded-xl bg-surface/70 p-3">
            <p class="flex items-center gap-1.5 text-xs font-bold text-muted"><span class="grid size-6 place-items-center rounded-full bg-expense/15 text-expense"><x-icon name="arrow-up" class="size-3.5"/></span> Gastos pagados</p>
            <x-money :usd="$summary['expense']" tone="text-expense" size="text-base sm:text-lg" class="mt-1"/>
        </div>
        @if($forecast['committed'] > 0)
        <a href="{{ route('fixed.index', ['mes' => $month->format('Y-m')]) }}" class="min-w-0 rounded-xl bg-expense/10 p-3 transition hover:bg-expense/15">
            <p class="flex items-center gap-1.5 text-xs font-bold text-muted"><span class="grid size-6 place-items-center rounded-full bg-expense/15 text-expense"><x-icon name="repeat" class="size-3.5"/></span> Gastos fijos por pagar</p>
            <x-money :usd="$forecast['fixed_pending']" tone="text-expense" size="text-base sm:text-lg" class="mt-1"/>
            <p class="num mt-1 text-xs text-muted">{{ $fixedTotals['unpaid_count'] ?? 0 }} {{ ($fixedTotals['unpaid_count'] ?? 0) == 1 ? 'pendiente' : 'pendientes' }} este mes</p>
        </a>
        <a href="{{ route('transactions.index', ['mes' => $month->format('Y-m')]) }}" class="min-w-0 rounded-xl bg-expense/10 p-3 transition hover:bg-expense/15">
            <p class="flex items-center gap-1.5 text-xs font-bold text-muted"><span class="grid size-6 place-items-center rounded-full bg-expense/15 text-expense"><x-icon name="clock" class="size-3.5"/></span> Gastos por pagar</p>
            <x-money :usd="$forecast['planned']" tone="text-expense" size="text-base sm:text-lg" class="mt-1"/>
            <p class="num mt-1 text-xs text-muted">{{ $forecast['planned_items']->count() }} {{ $forecast['planned_items']->count() == 1 ? 'gasto' : 'gastos' }} (imprevistos y programados)</p>
        </a>
        @endif
    </div>
</section>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    {{-- Gastos por categoría --}}
    <section class="card p-5">
        <h2 class="font-extrabold">¿En qué se va el dinero?</h2>
        @if($summary['by_category']->isEmpty())
            <p class="mt-3 text-sm text-muted">Aún no hay gastos este mes. Toca <b>+</b> para registrar el primero.</p>
        @else
            <div class="mt-3 grid items-center gap-4 sm:grid-cols-[150px_1fr]">
                <div class="relative mx-auto size-36"><canvas id="catChart" aria-label="Gasto por categoría" role="img"></canvas></div>
                <ul class="grid gap-2">
                    @foreach($summary['by_category']->take(6) as $c)
                        <li class="flex items-center justify-between gap-2 text-sm">
                            <span class="flex min-w-0 items-center gap-2"><span class="size-3 shrink-0 rounded-full" style="background:{{ $c->color }}"></span><span class="truncate">{{ $c->name }}</span></span>
                            <span class="num font-bold">{{ usd($c->total) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    {{-- Presupuestos --}}
    <section class="card p-5">
        <div class="flex items-center justify-between"><h2 class="font-extrabold">Presupuestos</h2><a class="text-sm font-bold text-brand" href="{{ route('budget.index') }}">Ver todo</a></div>
        @forelse($budgets->sortByDesc(fn ($c) => ($spent[$c->id] ?? 0) / max(1, (float) $c->monthly_budget))->take(4) as $c)
            @php $s = $spent[$c->id] ?? 0; $p = $c->monthly_budget > 0 ? min(100, $s / $c->monthly_budget * 100) : 0; $over = $s > $c->monthly_budget; @endphp
            <div class="mt-4">
                <div class="mb-1 flex justify-between text-sm"><span class="font-bold">{{ $c->fullName() }}</span><span class="num {{ $over ? 'font-bold text-expense' : 'text-muted' }}">{{ usd($s) }} / {{ usd($c->monthly_budget) }}</span></div>
                <div class="h-2.5 overflow-hidden rounded-full bg-surface2" role="progressbar" aria-valuenow="{{ round($p) }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $c->fullName() }}"><div class="h-full rounded-full" style="width:{{ $p }}%;background:{{ $over ? 'var(--expense)' : ($p > 80 ? 'var(--warn)' : 'var(--brand)') }}"></div></div>
            </div>
        @empty
            <p class="mt-3 text-sm text-muted">Define un límite mensual por categoría y te avisamos cuando te acerques.</p>
            <a href="{{ route('budget.index') }}" class="btn btn-ghost mt-3">Crear presupuesto</a>
        @endforelse
    </section>

    {{-- Gastos fijos: cuánto representan este mes (solo los fijos) --}}
    <section class="card p-5">
        <div class="flex items-center justify-between"><h2 class="font-extrabold">Solo gastos fijos</h2><a class="text-sm font-bold text-brand" href="{{ route('fixed.index', ['mes' => $month->format('Y-m')]) }}">Ver detalle</a></div>
        @if($fixedTotals['count'] === 0)
            <p class="mt-3 text-sm text-muted">Carga tu alquiler, servicios y colegio una vez y cada mes solo los marcas como pagados.</p>
            <a href="{{ route('fixed.index') }}" class="btn btn-ghost mt-3">Agregar gastos fijos</a>
        @else
            @php
                $fp = $fixedTotals['expected'] > 0 ? min(100, $fixedTotals['paid'] / $fixedTotals['expected'] * 100) : 0;
                $fixedShare = $summary['income'] > 0 ? round($fixedTotals['expected'] / $summary['income'] * 100) : null;
            @endphp
            <p class="mt-2 text-xs font-bold text-muted">Total de gastos fijos de {{ mes_es($month) }}</p>
            <x-money :usd="$fixedTotals['expected']" size="text-3xl" tone="text-ink"/>
            @if($fixedShare !== null)
                <p class="mt-1 text-xs text-muted">Es el <b class="text-ink">{{ $fixedShare }} %</b> de tus ingresos del mes{{ $fixedShare >= 60 ? ': una parte alta, deja poco margen para ahorrar' : '' }}.</p>
            @endif
            <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-surface2" role="progressbar" aria-valuenow="{{ round($fp) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Gastos fijos pagados"><div class="h-full rounded-full bg-brand" style="width:{{ $fp }}%"></div></div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Ya pagado</dt><dd class="num font-extrabold text-income">{{ money($fixedTotals['paid']) }}</dd><dd class="text-[11px] text-muted">{{ $fixedTotals['paid_count'] }} de {{ $fixedTotals['count'] }}</dd></div>
                <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Falta pagar</dt><dd class="num font-extrabold {{ $fixedTotals['overdue_count'] ? 'text-expense' : '' }}">{{ money($fixedTotals['pending']) }}</dd><dd class="text-[11px] text-muted">{{ $fixedTotals['unpaid_count'] }} {{ $fixedTotals['unpaid_count'] === 1 ? 'pendiente' : 'pendientes' }}</dd></div>
            </dl>
            @foreach($fixedRows->where('status', '!=', 'paid')->take(3) as $fr)
                <p class="mt-2 flex items-center justify-between gap-2 text-sm">
                    <span class="flex min-w-0 items-center gap-1.5 {{ $fr['status'] === 'overdue' ? 'font-bold text-expense' : '' }}"><x-icon :name="$fr['status'] === 'overdue' ? 'alert' : 'clock'" class="size-4 shrink-0"/> <span class="truncate">{{ $fr['fixed']->name }}</span></span>
                    <span class="num shrink-0 text-muted">{{ $fr['due']->translatedFormat('d M') }}</span>
                </p>
            @endforeach
        @endif
        @if($prevUnpaid > 0)
            <a href="{{ route('fixed.index', ['mes' => $month->copy()->subMonthNoOverflow()->format('Y-m')]) }}" class="mt-3 flex items-center gap-2 rounded-xl bg-expense/10 p-2.5 text-sm font-bold text-expense">
                <x-icon name="alert" class="size-4 shrink-0"/> {{ $prevUnpaid }} {{ $prevUnpaid == 1 ? 'gasto fijo' : 'gastos fijos' }} del mes pasado sin pagar
            </a>
        @endif
    </section>

    {{-- Metas --}}
    <section class="card p-5">
        <div class="flex items-center justify-between"><h2 class="font-extrabold">Tus metas</h2><a class="text-sm font-bold text-brand" href="{{ route('goals.index') }}">Ver todas</a></div>
        @forelse($goals as $g)
            <div class="mt-4">
                <div class="mb-1 flex justify-between text-sm"><span class="font-bold">{{ $g['goal']->name }}</span><span class="num text-muted">{{ $g['pct'] }}%</span></div>
                <div class="h-2.5 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full bg-info" style="width:{{ $g['pct'] }}%"></div></div>
                <p class="mt-1 text-xs text-muted">
                    @if($g['status'] === 'atrasada') <span class="font-bold text-warn">Vas atrasado</span> · necesitas {{ usd($g['required']) }}/mes
                    @elseif($g['status'] === 'a_tiempo') <span class="font-bold text-income">Vas a tiempo</span> · necesitas {{ usd($g['required']) }}/mes
                    @elseif($g['monthsToGo']) Al ritmo actual llegas en {{ $g['monthsToGo'] }} {{ $g['monthsToGo'] == 1 ? 'mes' : 'meses' }}
                    @else Registra ahorro para proyectar @endif
                </p>
            </div>
        @empty
            <p class="mt-3 text-sm text-muted">Crea una meta (un viaje, un carro…) y mide qué tan cerca estás.</p>
            <a href="{{ route('goals.index') }}" class="btn btn-ghost mt-3">Crear meta</a>
        @endforelse
    </section>

    {{-- Deudas --}}
    <section class="card p-5">
        <div class="flex items-center justify-between"><h2 class="font-extrabold">Deudas y préstamos</h2><a class="text-sm font-bold text-brand" href="{{ route('debts.index') }}">Ver todo</a></div>
        <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-xl bg-surface2 p-3"><p class="text-xs font-bold text-muted">Yo debo</p><x-money :usd="$owe" tone="text-expense" size="text-lg"/></div>
            <div class="rounded-xl bg-surface2 p-3"><p class="text-xs font-bold text-muted">Me deben</p><x-money :usd="$lent" tone="text-income" size="text-lg"/></div>
        </div>
    </section>
</div>

{{-- Últimos movimientos --}}
<section class="card mt-4 p-5">
    <div class="flex items-center justify-between"><h2 class="font-extrabold">Últimos movimientos</h2><a class="text-sm font-bold text-brand" href="{{ route('transactions.index') }}">Ver todos</a></div>
    @include('transactions._list', ['transactions' => $recent, 'deletable' => false])
</section>

@if($summary['by_category']->isNotEmpty())
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('catChart'); if (!el) return;
    finChart(el, { type: 'doughnut',
        data: { labels: @json($summary['by_category']->pluck('name')), datasets: [{ data: @json($summary['by_category']->pluck('total')->map(fn($v)=>(float)$v)), backgroundColor: @json($summary['by_category']->pluck('color')), borderWidth: 0 }] },
        options: { cutout: '68%', plugins: { legend: { display: false } } } });
});
</script>
@endif
</x-layouts.app>
