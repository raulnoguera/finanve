@props(['month'])
@php
 $prev = $month->copy()->subMonth()->format('Y-m');
 $next = $month->copy()->addMonth()->format('Y-m');
 $canNext = $month->copy()->addMonth()->startOfMonth()->lte(now());
@endphp
<div class="inline-flex items-center gap-1 card px-1 py-1">
    <a href="?mes={{ $prev }}" class="grid size-11 place-items-center rounded-xl hover:bg-surface2" aria-label="Mes anterior"><x-icon name="chev-left"/></a>
    <span class="min-w-32 text-center text-sm font-bold capitalize">{{ mes_es($month) }}</span>
    @if($canNext)
        <a href="?mes={{ $next }}" class="grid size-11 place-items-center rounded-xl hover:bg-surface2" aria-label="Mes siguiente"><x-icon name="chev-right"/></a>
    @else
        <span class="grid size-11 place-items-center opacity-30"><x-icon name="chev-right"/></span>
    @endif
</div>
