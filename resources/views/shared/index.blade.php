<x-layouts.app title="Compartidos">
@php
 $chip = ['paid' => ['Pagado', 'bg-brand-soft text-brand'], 'partial' => ['Pago parcial', 'bg-warn/15 text-warn'], 'pending' => ['Pendiente', 'bg-expense/10 text-expense']];
@endphp
<div class="mb-4">
    <p class="text-sm text-muted">Gastos que otras personas comparten contigo. Solo ves tu parte de cada gasto; nada más de su familia.</p>
</div>

@if($pending->isNotEmpty())
<section class="card rise mb-4 p-5" style="background:linear-gradient(135deg,var(--brand-soft),var(--surface))">
    <p class="text-sm font-bold text-muted">Te falta pagar</p>
    <p class="num mt-1 text-3xl font-extrabold">{{ $pending->join(' + ') }}</p>
</section>
@endif

@if($series->isNotEmpty())
<section class="card mb-4 p-5">
    <h2 class="font-extrabold">Tus gastos fijos compartidos</h2>
    <p class="text-sm text-muted">Pagos que se repiten: ya ves tu calendario de cuotas aunque todavía no se hayan registrado.</p>
    <ul class="mt-3 divide-y divide-line">
        @foreach($series as $sr)
            <li class="flex items-start justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="truncate font-bold">{{ $sr['name'] }}</p>
                    <p class="text-xs text-muted">{{ $sr['label'] }}@if($sr['owner']) · de {{ $sr['owner'] }}@endif</p>
                    @if($sr['next'])<p class="num mt-0.5 text-xs">Próxima cuota: <b>{{ $sr['next']->translatedFormat('d M') }}</b> · {{ $sr['open'] }} {{ $sr['open'] == 1 ? 'pendiente' : 'pendientes' }}</p>@endif
                    @if($sr['ends'])<p class="num text-xs">Última cuota: <b>{{ $sr['ends']->translatedFormat('d M Y') }}</b> · {{ $sr['cuotas']->count() }} {{ $sr['cuotas']->count() == 1 ? 'cuota' : 'cuotas' }} en total</p>@endif
                    @if($sr['cuotas']->count() > 1)
                        <details class="mt-1.5 text-xs">
                            <summary class="min-h-8 cursor-pointer font-bold text-brand">Ver todas las cuotas</summary>
                            <ol class="num mt-1 grid gap-0.5 rounded-lg bg-surface2 p-2">
                                @foreach($sr['cuotas'] as $c)
                                    <li class="flex items-center justify-between gap-3">
                                        <span>{{ $c['n'] }}/{{ $c['of'] }} · {{ $c['due']->translatedFormat('d M Y') }}</span>
                                        <span class="flex items-center gap-2"><b>{{ \App\Support\Money::format($c['amount'], $sr['cur']) }}</b>
                                            <span class="chip {{ ['paid' => 'bg-brand-soft text-brand', 'partial' => 'bg-warn/15 text-warn', 'pending' => 'bg-expense/10 text-expense', 'scheduled' => 'bg-surface text-muted'][$c['state']] }}">{{ ['paid' => 'Pagada', 'partial' => 'Parcial', 'pending' => 'Pendiente', 'scheduled' => 'Programada'][$c['state']] }}</span></span>
                                    </li>
                                @endforeach
                            </ol>
                        </details>
                    @endif
                </div>
                <div class="shrink-0 text-right"><p class="num font-extrabold">{{ \App\Support\Money::format($sr['per'], $sr['cur']) }}</p><p class="text-xs text-muted">{{ rtrim(rtrim(number_format((float) $sr['percent'], 2, '.', ''), '0'), '.') }} % por cuota</p></div>
            </li>
        @endforeach
    </ul>
</section>
@endif

@if($collect->isNotEmpty())
<section class="card rise mb-4 p-5" style="background:linear-gradient(135deg,var(--brand-soft),var(--surface))">
    <p class="text-sm font-bold text-muted">Te deben</p>
    <p class="num mt-1 text-3xl font-extrabold text-income">{{ $collect->join(' + ') }}</p>
</section>
@endif

<div class="grid gap-3">
@forelse($active as $d)
    @php
        $cur = $d->linkedPerson->household->currency();
        $st = $d->shareStatus();
        $what = $d->transaction?->note ?: ($d->transaction?->category?->fullName() ?? preg_replace('/^Gasto compartido: /', '', (string) $d->note));
        if ($d->kind === 'owe' && ! $d->transaction) { $what = $d->getAttribute('person') ?: $what; }
        $pct = rtrim(rtrim(number_format((float) $d->percent, 2, '.', ''), '0'), '.');
        $paidPct = $d->amount > 0 ? min(100, $d->paid() / $d->amount * 100) : 0;
        $late = $d->due_date && $d->due_date->isPast() && $st !== 'paid';
    @endphp
    <article class="card p-4 {{ $st === 'paid' ? 'bg-surface2/50' : '' }}" x-data="{ pay: false }">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate font-extrabold">{{ $what ?: 'Gasto compartido' }}@if($d->fixedExpense) <span class="chip ml-1 bg-info/10 align-middle text-info">Gasto fijo</span>@endif</p>
                <p class="truncate text-xs text-muted">
                    @if($d->kind === 'owe') <b>{{ $d->user?->name ?? $d->linkedPerson->household->name }}</b> te debe esto @else De {{ $d->user?->name ?? $d->linkedPerson->household->name }} · {{ $d->kind === 'shared' ? 'pagas tu parte directamente' : 'reembolso a quien pagó el total' }} @endif
                    @if($d->transaction) · {{ $d->transaction->date->translatedFormat('d M') }} · total {{ \App\Support\Money::format($d->transaction->amount, $cur) }} @endif
                </p>
            </div>
            <span class="chip shrink-0 {{ $chip[$st][1] }}">{{ $chip[$st][0] }}</span>
        </div>

        @php
            $total = (float) $d->percent > 0 ? round($d->amount / $d->percent * 100, 2) : null;
            $occ = $d->fixedExpense && $d->fixed_due ? $d->fixedExpense->occurrencesBetween($d->fixed_due->copy()->startOfDay(), $d->fixed_due->copy()->startOfDay())->first() : null;
            $since = $d->transaction?->date ?? $d->created_at;
        @endphp
        <dl class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-lg bg-surface2 p-2"><dt class="text-[11px] font-bold uppercase tracking-wide text-muted">{{ $total && $occ ? 'Cuota total' : 'Gasto total' }}</dt><dd class="num font-extrabold">{{ $total ? \App\Support\Money::format($total, $cur) : '—' }}</dd></div>
            <div class="rounded-lg bg-surface2 p-2"><dt class="text-[11px] font-bold uppercase tracking-wide text-muted">{{ $d->kind === 'owe' ? 'Su porcentaje' : 'Tu porcentaje' }}</dt><dd class="num font-extrabold">{{ (float) $d->percent > 0 ? $pct.' %' : '—' }}</dd></div>
            <div class="rounded-lg bg-surface2 p-2"><dt class="text-[11px] font-bold uppercase tracking-wide text-muted">{{ $d->kind === 'owe' ? 'Te deben' : 'Tu parte' }}</dt><dd class="num font-extrabold">{{ \App\Support\Money::format($d->amount, $cur) }}</dd></div>
            <div class="rounded-lg bg-surface2 p-2"><dt class="text-[11px] font-bold uppercase tracking-wide text-muted">Registrado</dt><dd class="num font-extrabold">{{ $since->translatedFormat('d M Y') }}</dd></div>
        </dl>
        <div class="mt-3 flex items-end justify-between gap-3">
            <div><p class="text-xs font-bold text-muted">Pagado</p><p class="num text-lg font-extrabold text-income">{{ \App\Support\Money::format($d->paid(), $cur) }}</p></div>
            @if($occ && $occ['of'])<span class="chip bg-info/10 text-info">Cuota {{ $occ['n'] }} de {{ $occ['of'] }}</span>@endif
            <div class="text-right"><p class="text-xs font-bold text-muted">Falta</p><p class="num text-2xl font-extrabold {{ $st === 'paid' ? 'text-income' : '' }}">{{ \App\Support\Money::format($d->remaining(), $cur) }}</p></div>
        </div>
        <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-surface2" role="progressbar" aria-valuenow="{{ round($paidPct) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance del pago">
            <div class="h-full rounded-full bg-brand" style="width:{{ $paidPct }}%"></div>
        </div>
        <p class="mt-1.5 text-xs {{ $late ? 'font-bold text-expense' : 'text-muted' }}">
            @if($d->due_date) {{ $late ? 'Venció' : ($d->kind === 'owe' ? 'Te lo pagan antes del' : 'Pagar antes del') }} {{ $d->due_date->translatedFormat('d M Y') }} @else Sin fecha límite @endif
        </p>

        @if($d->payments->isNotEmpty())
            <ul class="mt-2 grid gap-0.5 rounded-lg bg-surface2 p-2 text-xs">
                @foreach($d->payments as $pm)
                    <li class="num flex justify-between gap-3"><span class="text-muted">{{ $pm->date->translatedFormat('d M') }}{{ $pm->reporter ? ($d->kind === 'owe' ? ' · confirmado por ti' : ' · reportado por ti') : ' · registrado por '.($d->user?->name ?? 'el titular') }}</span><b>{{ \App\Support\Money::format($pm->amount, $cur) }}</b></li>
                @endforeach
            </ul>
        @endif

        @if($st !== 'paid')
            <button type="button" class="btn btn-primary mt-3 w-full" @click="pay = !pay"><x-icon name="check"/> {{ $d->kind === 'owe' ? 'Ya me pagó' : 'Ya pagué' }}</button>
            <form x-show="pay" x-cloak method="POST" action="{{ route('shared.pay', $d->id) }}" class="mt-3 grid gap-2">
                @csrf
                <div>
                    <label class="label" for="sp{{ $d->id }}">{{ $d->kind === 'owe' ? '¿Cuánto recibiste?' : '¿Cuánto pagaste?' }} ({{ $cur }})</label>
                    <input id="sp{{ $d->id }}" class="field num text-lg font-extrabold" name="amount" type="number" step="0.01" min="0.01" max="{{ $d->remaining() }}" value="{{ $d->remaining() + 0 }}" inputmode="decimal" required>
                </div>
                <input class="field" name="note" maxlength="140" placeholder="Nota (opcional): transferencia, efectivo…" aria-label="Nota del pago">
                <button class="btn btn-ghost">{{ $d->kind === 'owe' ? 'Confirmar cobro' : 'Reportar pago' }}</button>
                <p class="text-xs text-muted">Se le avisará a {{ $d->user?->name ?? 'quien compartió el gasto' }} para que lo confirme en su registro.</p>
            </form>
        @endif
    </article>
@empty
    <div class="card p-8 text-center">
        <x-icon name="share" class="mx-auto size-10 text-brand"/>
        <p class="mt-3 font-extrabold">Aún no te han compartido gastos</p>
        <p class="text-sm text-muted">Cuando alguien comparta un gasto contigo, verás aquí cuánto te toca y podrás reportar tus pagos.</p>
    </div>
@endforelse
</div>

@if($history->isNotEmpty() || $timeline->isNotEmpty())
<section class="card mt-4 p-5" aria-labelledby="hist-h">
    <h2 id="hist-h" class="font-extrabold">Historial</h2>
    <p class="text-sm text-muted">Lo que ya quedó saldado y todos los pagos que has registrado, del más reciente al más antiguo.</p>

    @if($history->isNotEmpty())
    <h3 class="mt-4 text-xs font-bold uppercase tracking-wide text-muted">Saldados ({{ $history->count() }})</h3>
    <ul class="mt-1 divide-y divide-line">
        @foreach($history as $d)
            @php
                $hc = $d->linkedPerson->household->currency();
                $hw = $d->transaction?->note ?: ($d->transaction?->category?->fullName() ?? preg_replace('/^Gasto compartido: /', '', (string) $d->note));
                if ($d->kind === 'owe' && ! $d->transaction) { $hw = $d->getAttribute('person') ?: $hw; }
                $hp = rtrim(rtrim(number_format((float) $d->percent, 2, '.', ''), '0'), '.');
            @endphp
            <li class="flex items-start justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="truncate font-bold">{{ $hw ?: 'Gasto compartido' }}</p>
                    <p class="text-xs text-muted">De {{ $d->user?->name ?? $d->linkedPerson->household->name }}@if((float) $d->percent > 0) · {{ $hp }} %@endif · registrado el {{ ($d->transaction?->date ?? $d->created_at)->translatedFormat('d M Y') }} · saldado el {{ $d->settled_at->translatedFormat('d M Y') }}</p>
                </div>
                <b class="num shrink-0">{{ \App\Support\Money::format($d->amount, $hc) }}</b>
            </li>
        @endforeach
    </ul>
    @endif

    @if($timeline->isNotEmpty())
    <h3 class="mt-5 text-xs font-bold uppercase tracking-wide text-muted">Pagos</h3>
    <ul class="mt-1 divide-y divide-line">
        @foreach($timeline as $x)
            @php
                $td = $x['debt']; $tp = $x['pay'];
                $tw = $td->transaction?->note ?: ($td->transaction?->category?->fullName() ?? preg_replace('/^Gasto compartido: /', '', (string) $td->note));
                if ($td->kind === 'owe' && ! $td->transaction) { $tw = $td->getAttribute('person') ?: $tw; }
            @endphp
            <li class="flex items-start justify-between gap-3 py-2.5 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-bold">{{ $tw ?: 'Gasto compartido' }}</p>
                    <p class="text-xs text-muted">{{ $tp->date->translatedFormat('d M Y') }} · {{ $tp->reporter ? ($td->kind === 'owe' ? 'confirmado por ti' : 'reportado por ti') : 'registrado por '.($td->user?->name ?? 'el titular') }}@if($tp->note) · {{ $tp->note }}@endif</p>
                </div>
                <b class="num shrink-0 text-income">{{ \App\Support\Money::format($tp->amount, $td->linkedPerson->household->currency()) }}</b>
            </li>
        @endforeach
    </ul>
    @endif
</section>
@endif
</x-layouts.app>
