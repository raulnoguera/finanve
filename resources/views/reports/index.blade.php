<x-layouts.app title="Reportes">
@php
 $exp = max(0.0001, $summary['expense']);
 $fixedPct = $summary['expense'] > 0 ? round($fixedPaid / $exp * 100) : 0;
 $diff = $summary['expense'] - $prev['expense'];
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-sm text-muted">Reporte de</p>
        <p class="text-xl font-extrabold capitalize tracking-tight">{{ mes_es($month) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <x-month-nav :month="$month"/>
        <a href="{{ route('reports.export', ['mes' => $month->format('Y-m')]) }}" class="btn btn-ghost" title="Descargar los movimientos del mes en CSV (Excel)"><x-icon name="copy" class="size-4"/> CSV</a>
    </div>
</div>

{{-- Cifras --}}
<section class="grid grid-cols-2 gap-3 lg:grid-cols-4 rise">
    <div class="card min-w-0 p-4"><p class="text-xs font-bold text-muted">Ingresos</p><x-money :usd="$summary['income']" tone="text-income" size="text-lg"/></div>
    <div class="card min-w-0 p-4"><p class="text-xs font-bold text-muted">Gastos</p><x-money :usd="$summary['expense']" tone="text-expense" size="text-lg"/>
        @if($prev['expense'] > 0)<p class="mt-1 text-[11px] font-bold {{ $diff > 0 ? 'text-expense' : 'text-income' }}">{{ $diff > 0 ? '▲' : '▼' }} {{ money(abs($diff)) }} vs. mes anterior</p>@endif</div>
    <div class="card min-w-0 p-4"><p class="text-xs font-bold text-muted">Lo que te queda</p><x-money :usd="$summary['balance']" :tone="$summary['balance'] < 0 ? 'text-expense' : 'text-ink'" size="text-lg"/>
        @if(($summary['saved'] ?? 0) > 0)<p class="mt-1 text-[11px] text-muted">tras apartar {{ money($summary['saved']) }} para metas</p>@endif</div>
    <div class="card min-w-0 p-4"><p class="text-xs font-bold text-muted">Ahorro</p><p class="num text-lg font-extrabold">{{ $summary['savings_rate'] !== null ? $summary['savings_rate'].'%' : '—' }}</p><p class="text-[11px] text-muted">de tus ingresos</p></div>
</section>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    {{-- Fijos vs imprevistos --}}
    <section class="card p-5">
        <h2 class="font-extrabold">Fijos vs. imprevistos</h2>
        <p class="text-sm text-muted">Qué parte de tus gastos fue planificada y cuál no.</p>
        @if($summary['expense'] > 0)
            <div class="mt-4 flex h-5 overflow-hidden rounded-full bg-surface2" role="img" aria-label="{{ $fixedPct }}% gastos fijos, {{ 100 - $fixedPct }}% imprevistos y variables">
                <div class="h-full bg-info" style="width:{{ $fixedPct }}%"></div>
                <div class="h-full bg-warn" style="width:{{ 100 - $fixedPct }}%"></div>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="flex items-center gap-1.5 font-bold"><span class="size-3 rounded-full bg-info"></span> Gastos fijos</dt><dd><x-money :usd="$fixedPaid"/><span class="text-xs text-muted"> · {{ $fixedPct }}%</span></dd></div>
                <div><dt class="flex items-center gap-1.5 font-bold"><span class="size-3 rounded-full bg-warn"></span> Imprevistos y variables</dt><dd><x-money :usd="$variable"/><span class="text-xs text-muted"> · {{ 100 - $fixedPct }}%</span></dd></div>
            </dl>
        @else
            <p class="mt-3 text-sm text-muted">Sin gastos este mes.</p>
        @endif
        @if($fixedTotals['count'])
            <div class="mt-4 rounded-xl bg-surface2 p-3 text-sm">
                <p class="font-bold">Cumplimiento de fijos: {{ $fixedTotals['paid_count'] }} de {{ $fixedTotals['count'] }} {{ $fixedTotals['paid_count'] === 1 ? 'pagado' : 'pagados' }}</p>
                @if($fixedTotals['unpaid_count'])
                    <p class="mt-1 text-expense">Sin pagar: {{ $fixedRows->where('status', '!=', 'paid')->map(fn ($r) => $r['fixed']->name)->join(', ') }} ({{ money($fixedTotals['pending']) }})</p>
                    <a class="mt-1 inline-block font-bold text-brand" href="{{ route('fixed.index', ['mes' => $month->format('Y-m')]) }}">Ir a gastos fijos</a>
                @else
                    <p class="mt-1 text-income">¡Todo al día!</p>
                @endif
            </div>
        @endif
    </section>

    {{-- Tendencia --}}
    <section class="card p-5">
        <h2 class="font-extrabold">Últimos 6 meses</h2>
        <div class="relative mt-3 h-56"><canvas id="trend" role="img" aria-label="Ingresos y gastos de los últimos 6 meses"></canvas></div>
    </section>
</div>

{{-- Por categoría --}}
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Gasto por categoría</h2>
    @if($cats->isEmpty() && $uncategorized <= 0)
        <p class="mt-3 text-sm text-muted">Sin gastos este mes.</p>
    @else
    <ul class="mt-3 divide-y divide-line">
        @foreach($cats as $c)
            @php $p = round($c['total'] / $exp * 100); @endphp
            <li class="py-3">
                <div class="flex items-center justify-between gap-3">
                    <span class="flex min-w-0 items-center gap-2 font-bold"><span class="size-3 shrink-0 rounded-full" style="background:{{ $c['color'] }}"></span><span class="truncate">{{ $c['name'] }}</span></span>
                    <span class="num shrink-0 text-sm"><b>{{ money($c['total']) }}</b> <span class="text-muted">· {{ $p }}%</span></span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full" style="width:{{ $p }}%;background:{{ $c['color'] }}"></div></div>
                @if($c['budget'] > 0)
                    <p class="num mt-1 text-xs {{ $c['total'] > $c['budget'] ? 'font-bold text-expense' : 'text-muted' }}">Presupuesto {{ money($c['budget']) }} · {{ $c['total'] > $c['budget'] ? 'te pasaste por '.money($c['total'] - $c['budget']) : 'usaste el '.round($c['total'] / $c['budget'] * 100).'%' }}</p>
                @endif
                @if($c['children']->isNotEmpty())
                    <ul class="mt-2 grid gap-1 pl-5 text-xs text-muted">
                        @foreach($c['children'] as $k)<li class="num flex justify-between"><span>{{ $k['name'] }}</span><span>{{ money($k['total']) }}</span></li>@endforeach
                        @if($c['own'] > 0 && $c['children']->isNotEmpty())<li class="num flex justify-between"><span>General</span><span>{{ money($c['own']) }}</span></li>@endif
                    </ul>
                @endif
            </li>
        @endforeach
        @if($uncategorized > 0)
            <li class="flex items-center justify-between py-3 text-sm"><span class="font-bold text-muted">Sin categoría</span><span class="num font-bold">{{ money($uncategorized) }}</span></li>
        @endif
    </ul>
    @endif
</section>

{{-- Por persona --}}
@if($byPerson->isNotEmpty())
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Gasto por persona</h2>
    <p class="text-sm text-muted">Cuánto se gastó en cada integrante este mes y en qué.</p>
    <ul class="mt-3 grid gap-4">
        @foreach($byPerson as $row)
            @php $pp = round($row['total'] / $exp * 100); @endphp
            <li>
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('transactions.index', ['mes' => $month->format('Y-m'), 'tipo' => 'expense', 'persona' => $row['person']->id]) }}" class="flex min-w-0 items-center gap-2 font-bold hover:text-brand">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full text-xs font-extrabold text-white" style="background:{{ $row['person']->color }}">{{ mb_strtoupper(mb_substr($row['person']->name, 0, 1)) }}</span>
                        <span class="truncate">{{ $row['person']->name }}</span>
                    </a>
                    <span class="num shrink-0 text-sm"><b>{{ money($row['total']) }}</b> <span class="text-muted">· {{ $pp }}%</span></span>
                </div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full" style="width:{{ $pp }}%;background:{{ $row['person']->color }}"></div></div>
                <p class="num mt-1 text-xs text-muted">{{ $row['cats']->map(fn ($v, $k) => $k.' '.money($v))->values()->join(' · ') }}</p>
            </li>
        @endforeach
    </ul>
</section>
@endif

{{-- ¿Dónde o para quién? --}}
@if($byPlace->isNotEmpty())
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">¿Dónde se gastó?</h2>
    <p class="text-sm text-muted">Gastos del mes por lugar.</p>
    <ul class="mt-3 grid gap-3">
        @foreach($byPlace as $pl)
            @php $pp = round($pl->total / $exp * 100); @endphp
            <li>
                <div class="flex items-center justify-between gap-3 text-sm">
                    <a href="{{ route('transactions.index', ['mes' => $month->format('Y-m'), 'tipo' => 'expense', 'lugar' => $pl->place]) }}" class="min-w-0 truncate font-bold hover:text-brand">{{ $pl->place }} <span class="text-xs font-medium text-muted">· {{ $pl->n }} {{ $pl->n == 1 ? 'gasto' : 'gastos' }}</span></a>
                    <span class="num shrink-0"><b>{{ money($pl->total) }}</b> <span class="text-muted">· {{ $pp }}%</span></span>
                </div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full bg-info" style="width:{{ $pp }}%"></div></div>
            </li>
        @endforeach
    </ul>
    @if($noPlace > 0)<p class="num mt-3 text-xs text-muted">Sin indicar lugar: {{ money($noPlace) }}</p>@endif
</section>
@endif

{{-- Ingresos por categoría --}}
@if($incomeCats->isNotEmpty() || $incomeUncategorized > 0)
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Ingresos por categoría</h2>
    <ul class="mt-3 divide-y divide-line">
        @php $incTotal = max(0.0001, $summary['income']); @endphp
        @foreach($incomeCats as $c)
            @php $p = round($c['total'] / $incTotal * 100); @endphp
            <li class="py-3">
                <div class="flex items-center justify-between gap-3">
                    <span class="flex min-w-0 items-center gap-2 font-bold"><span class="size-3 shrink-0 rounded-full" style="background:{{ $c['color'] }}"></span><span class="truncate">{{ $c['name'] }}</span></span>
                    <span class="num shrink-0 text-sm"><b class="text-income">{{ money($c['total']) }}</b> <span class="text-muted">· {{ $p }}%</span></span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface2"><div class="h-full rounded-full" style="width:{{ $p }}%;background:{{ $c['color'] }}"></div></div>
                @if($c['children']->isNotEmpty())
                    <ul class="mt-2 grid gap-1 pl-5 text-xs text-muted">
                        @foreach($c['children'] as $k)<li class="num flex justify-between"><span>{{ $k['name'] }}</span><span>{{ money($k['total']) }}</span></li>@endforeach
                        @if($c['own'] > 0)<li class="num flex justify-between"><span>General</span><span>{{ money($c['own']) }}</span></li>@endif
                    </ul>
                @endif
            </li>
        @endforeach
        @if($incomeUncategorized > 0)
            <li class="flex items-center justify-between py-3 text-sm"><span class="font-bold text-muted">Sin categoría</span><span class="num font-bold">{{ money($incomeUncategorized) }}</span></li>
        @endif
    </ul>
</section>
@endif

{{-- Mayores gastos --}}
@if($biggest->isNotEmpty())
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Los 5 gastos más grandes</h2>
    <ol class="mt-2 divide-y divide-line">
        @foreach($biggest as $t)
            <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span class="min-w-0"><span class="block truncate font-bold">{{ $t->note ?: ($t->category?->fullName() ?? 'Sin categoría') }}</span><span class="text-xs text-muted">{{ $t->category?->fullName() ?? 'Sin categoría' }} · {{ $t->date->translatedFormat('d M') }}@if($t->fixed_month) · fijo @endif</span></span>
                <span class="num shrink-0 font-extrabold">{{ money($t->amount) }}</span>
            </li>
        @endforeach
    </ol>
</section>
@endif

<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('trend'); if (!el) return;
    const css = getComputedStyle(document.documentElement);
    finChart(el, { type: 'bar',
        data: { labels: @json($trend->pluck('label')), datasets: [
            { label: 'Ingresos', data: @json($trend->pluck('income')->map(fn ($v) => (float) $v)), backgroundColor: css.getPropertyValue('--income').trim(), borderRadius: 6 },
            { label: 'Gastos', data: @json($trend->pluck('expense')->map(fn ($v) => (float) $v)), backgroundColor: css.getPropertyValue('--expense').trim(), borderRadius: 6 },
        ] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: css.getPropertyValue('--border').trim() } } } } });
});
</script>
</x-layouts.app>
