@if($transactions->isEmpty())
    <p class="mt-3 text-sm text-muted">Sin movimientos todavía.</p>
@else
<ul class="mt-2 divide-y divide-line">
    @foreach($transactions as $t)
        <li class="flex items-center gap-3 py-3">
            @php $isSaving = $t->type === 'saving'; $col = $isSaving ? '#4f46e5' : ($t->category?->color ?? '#94a3b8'); @endphp
            <span class="grid size-10 shrink-0 place-items-center rounded-full" style="background:{{ $col }}22;color:{{ $col }}">
                <x-icon :name="$isSaving ? 'target' : ($t->type === 'income' ? 'arrow-down' : 'arrow-up')" class="size-5"/>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold">{{ $t->note ?: ($t->category?->fullName() ?? 'Sin categoría') }}</p>
                @if($t->person)<p class="mt-0.5"><span class="chip !py-0 text-[11px] text-white" style="background:{{ $t->person->color }}">{{ $t->person->name }}</span></p>@endif
                <p class="truncate text-xs text-muted">{{ $isSaving ? 'Ahorro · '.($t->goal?->name ?? 'meta') : ($t->category?->fullName() ?? 'Sin categoría') }} · {{ $t->date->translatedFormat('d M') }}@if($t->place) · {{ $t->place }}@endif · {{ explode(' ', $t->user->name)[0] }}</p>
                @if($t->relationLoaded('shares') && $t->shares->isNotEmpty())
                    <ul class="mt-1 grid gap-0.5">
                        @foreach($t->shares as $sh)
                            @php
                                $st = $sh->shareStatus();
                                $tag = ['paid' => ['Pagó', 'text-income'], 'partial' => ['Pagó una parte', 'text-warn'], 'pending' => ['Pendiente', 'text-expense']][$st];
                            @endphp
                            <li class="num flex flex-wrap items-center gap-x-2 text-xs">
                                <x-icon name="users" class="size-3.5 text-muted"/>
                                <span class="font-bold">{{ $sh->person }} {{ rtrim(rtrim(number_format($sh->percent, 2, '.', ''), '0'), '.') }} %</span>
                                <span class="text-muted">{{ money($sh->amount) }}</span>
                                <span class="font-bold {{ $tag[1] }}">{{ $tag[0] }}@if($st === 'partial') · falta {{ money($sh->remaining()) }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if(!empty($t->items))
                    <details class="mt-1 text-xs">
                        <summary class="cursor-pointer font-bold text-brand">{{ count($t->items) }} {{ count($t->items) === 1 ? 'producto' : 'productos' }}</summary>
                        <ul class="mt-1 grid gap-0.5 rounded-lg bg-surface2 p-2">
                            @foreach($t->items as $it)
                                <li class="num flex justify-between gap-3"><span class="truncate">{{ $it['qty'] > 1 ? $it['qty'].' × ' : '' }}{{ $it['name'] ?: 'Producto' }}</span><span>{{ money($it['price'] * $it['qty']) }}</span></li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if(($deletable ?? false) && isset($categories) && ! $isSaving)
                    <div x-data="{ more: false }" class="mt-1">
                        <button type="button" class="min-h-9 text-xs font-bold text-muted hover:text-brand" @click="more = !more" :aria-expanded="more">Editar detalles</button>
                        <div x-show="more" x-cloak class="mt-1 flex flex-wrap gap-1.5">
                            <form method="POST" action="{{ route('transactions.update', $t) }}">
                                @csrf @method('PATCH')
                                <label class="sr-only" for="cat-{{ $t->id }}">Cambiar categoría</label>
                                <select id="cat-{{ $t->id }}" name="category_id" onchange="this.form.submit()" class="max-w-full rounded-lg border border-line bg-surface px-2 py-1 text-xs text-muted">
                                    <option value="">Sin categoría</option>
                                    @foreach($categories->where('type', $t->type)->whereNull('parent_id') as $c)
                                        @php $subs = $categories->where('parent_id', $c->id); @endphp
                                        @if($subs->isNotEmpty())
                                            <optgroup label="{{ $c->name }}">
                                                <option value="{{ $c->id }}" @selected($t->category_id === $c->id)>{{ $c->name }} (general)</option>
                                                @foreach($subs as $k)<option value="{{ $k->id }}" @selected($t->category_id === $k->id)>{{ $k->name }}</option>@endforeach
                                            </optgroup>
                                        @else
                                            <option value="{{ $c->id }}" @selected($t->category_id === $c->id)>{{ $c->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </form>
                            @if($t->type === 'expense' && isset($people))
                            <form method="POST" action="{{ route('transactions.update', $t) }}">
                                @csrf @method('PATCH')
                                <label class="sr-only" for="per-{{ $t->id }}">¿Para quién?</label>
                                <select id="per-{{ $t->id }}" name="person_id" onchange="this.form.submit()" class="max-w-full rounded-lg border border-line bg-surface px-2 py-1 text-xs text-muted">
                                    <option value="">Sin persona</option>
                                    @foreach($people as $pp)<option value="{{ $pp->id }}" @selected($t->person_id === $pp->id)>{{ $pp->formLabel(auth()->id()) }}</option>@endforeach
                                </select>
                            </form>
                            @endif
                            @if($t->type === 'expense' && isset($people) && $people->whereNull('member_user_id')->isNotEmpty())
                            <div class="basis-full" x-data="{ share: false }">
                                <button type="button" class="min-h-9 text-xs font-bold text-brand" @click="share = !share" :aria-expanded="share">{{ $t->shares->isNotEmpty() ? 'Cambiar cómo se comparte' : 'Compartir este gasto' }}</button>
                                @php
                                    $rows = $people->whereNull('member_user_id')->map(fn ($pp) => [
                                        'id' => $pp->id, 'name' => $pp->name,
                                        'on' => $t->shares->contains('person_id', $pp->id),
                                        'percent' => (float) ($t->shares->firstWhere('person_id', $pp->id)->percent ?? ''),
                                    ])->values();
                                @endphp
                                <form x-show="share" x-cloak method="POST" action="{{ route('transactions.share', $t) }}"
                                      x-data="share({ amount: {{ (float) ($t->total_amount ?? $t->amount) }}, rows: {{ \Illuminate\Support\Js::from($rows) }} })"
                                      class="mt-2 rounded-xl border border-line bg-surface2 p-3">
                                    @csrf
                                    <p class="text-xs text-muted">Marca quién paga parte de este gasto y qué porcentaje. Lo que no asignes queda a tu cargo.</p>
                                    @php $sharedMode = $t->shares->first()?->kind === 'lent' ? 'reimburse' : 'split'; @endphp
                                    <div class="mt-2 grid gap-1.5 sm:grid-cols-2" x-show="rows.some(r => r.on)" x-cloak>
                                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line bg-surface p-2 text-xs has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                                            <input type="radio" name="mode" value="split" class="mt-0.5" @checked($sharedMode === 'split')><span><b>Cada quien paga su parte</b><br>Tu gasto es solo tu %</span>
                                        </label>
                                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line bg-surface p-2 text-xs has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                                            <input type="radio" name="mode" value="reimburse" class="mt-0.5" @checked($sharedMode === 'reimburse')><span><b>Yo pago el total</b><br>Me reembolsan su parte</span>
                                        </label>
                                    </div>
                                    <div class="mt-2 grid gap-2">
                                        <template x-for="r in rows" :key="r.id">
                                            <div class="flex items-center gap-2">
                                                <label class="flex min-h-10 min-w-0 flex-1 items-center gap-2 text-sm font-bold">
                                                    <input type="checkbox" class="size-5 shrink-0" x-model="r.on" @change="toggle(r)">
                                                    <span class="truncate" x-text="r.name"></span>
                                                </label>
                                                <div class="relative w-24" x-show="r.on">
                                                    <input type="number" step="0.01" min="0.01" max="100" inputmode="decimal" x-model="r.percent" class="field num !min-h-10 pr-6 text-sm" :aria-label="'Porcentaje de ' + r.name">
                                                    <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-xs font-bold text-muted">%</span>
                                                </div>
                                                <span class="num w-24 shrink-0 text-right text-xs text-muted" x-show="r.on" x-text="money(r.percent)"></span>
                                                <input type="hidden" :name="'shares[' + r.id + ']'" :value="r.percent" :disabled="!r.on">
                                            </div>
                                        </template>
                                    </div>
                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <span class="num" :class="invalid ? 'font-bold text-expense' : 'text-muted'">
                                            Tu parte: <b x-text="mine + ' % · ' + money(mine)"></b>
                                            <template x-if="total > 100.0001"><span> · suman más de 100 %</span></template>
                                        </span>
                                        <button type="button" class="font-bold text-brand" @click="equal()">Dividir en partes iguales</button>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <button class="btn btn-primary !min-h-10" :disabled="invalid" :class="invalid && 'opacity-50'">Guardar</button>
                                        <label class="ml-auto flex items-center gap-2 text-xs text-muted">Pagar antes de <input type="date" name="due_date" class="rounded-lg border border-line bg-surface px-2 py-1 text-xs"></label>
                                    </div>
                                </form>
                            </div>
                            @endif
                            <form method="POST" action="{{ route('transactions.update', $t) }}">
                                @csrf @method('PATCH')
                                <label class="sr-only" for="pl-{{ $t->id }}">¿Dónde?</label>
                                <input id="pl-{{ $t->id }}" name="place" value="{{ $t->place }}" list="all-places" maxlength="60" autocomplete="off"
                                       placeholder="¿Dónde?" onchange="this.form.submit()"
                                       class="w-44 max-w-full rounded-lg border border-line bg-surface px-2 py-1 text-xs text-muted">
                            </form>
                        </div>
                    </div>
                @endif
            </div>
            <div class="text-right">
                <x-money :usd="$t->type === 'income' ? $t->amount : -$t->amount" :sign="$t->type === 'income'" :tone="$t->type === 'income' ? 'text-income' : 'text-ink'" class="items-end"/>
                @if($t->total_amount)<p class="num text-[11px] text-muted">tu parte · total {{ money($t->total_amount) }}</p>@endif
                @if($t->currency === 'VES')<p class="text-[11px] text-muted">Pagado en Bs</p>@endif
            </div>
            @if($deletable ?? false)
                <form method="POST" action="{{ route('transactions.destroy', $t) }}" onsubmit="return confirm('¿Eliminar este movimiento?')">@csrf @method('DELETE')
                    <button class="grid size-10 place-items-center rounded-lg text-muted hover:bg-surface2 hover:text-expense" aria-label="Eliminar movimiento"><x-icon name="trash" class="size-4"/></button>
                </form>
            @endif
        </li>
    @endforeach
</ul>
@endif
