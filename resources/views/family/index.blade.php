<x-layouts.app title="Familia">
<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div><h2 class="font-extrabold">{{ $household->name }}</h2><p class="text-sm text-muted">Todos los miembros ven y registran sobre el mismo presupuesto.</p></div>
    </div>
    <ul class="mt-3 divide-y divide-line">
        @foreach($members as $m)
            <li class="flex items-center gap-3 py-3">
                <span class="grid size-10 place-items-center rounded-full bg-brand-soft font-extrabold text-brand">{{ mb_strtoupper(mb_substr($m->name, 0, 1)) }}</span>
                <div class="min-w-0 flex-1"><p class="truncate font-bold">{{ $m->name }} @if($m->id === auth()->id()) <span class="text-xs font-medium text-muted">(tú)</span> @endif</p><p class="truncate text-xs text-muted">{{ $m->email }} · {{ $m->isOwner() ? 'Administrador' : 'Miembro' }}</p></div>
                @if(auth()->user()->isOwner() && $m->id !== auth()->id())
                    <form method="POST" action="{{ route('family.remove', $m) }}" onsubmit="return confirm('¿Quitar a {{ $m->name }} de la familia?')">@csrf @method('DELETE')<button class="text-xs font-bold text-muted hover:text-expense">Quitar</button></form>
                @endif
            </li>
        @endforeach
    </ul>
</section>

<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Personas de la familia</h2>
    <p class="text-sm text-muted">Hijos, hermanos, pareja, mascotas… para ver cuánto se gasta en cada uno. No necesitan cuenta, pero pueden tenerla para ver y pagar los gastos que les compartas.</p>
    <ul class="mt-3 divide-y divide-line">
        @forelse($people as $p)
            <li class="py-2" x-data="{ edit: false }">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-extrabold text-white" style="background:{{ $p->color }}">{{ mb_strtoupper(mb_substr($p->name, 0, 1)) }}</span>
                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate font-bold">{{ $p->name }}</span>
                        <span class="num block text-xs text-muted">{{ $p->relationLabel() ?? 'Persona' }}@if($p->email) · {{ $p->email }}@endif @if(($p->month_total ?? 0) > 0) · {{ money($p->month_total) }} este mes @endif</span>
                    </span>
                    <a class="min-h-10 px-1 text-xs font-bold text-brand" href="{{ route('transactions.index', ['persona' => $p->id, 'tipo' => 'expense']) }}">Ver gastos</a>
                    <button type="button" class="min-h-10 px-1 text-xs font-bold text-muted hover:text-brand" @click="edit = !edit">Editar</button>
                    <form method="POST" action="{{ route('people.destroy', $p) }}" onsubmit="return confirm('¿Eliminar a {{ $p->name }}? Sus gastos se conservan sin persona asignada.')">@csrf @method('DELETE')
                        <button class="grid size-10 place-items-center rounded-lg text-muted hover:text-expense" aria-label="Eliminar a {{ $p->name }}"><x-icon name="trash" class="size-4"/></button>
                    </form>
                </div>
                {{-- Cuenta propia: solo verá los gastos que le compartas --}}
                <div class="mt-2 ml-[3.25rem] text-xs" x-data="{ inv: false }">
                    @if($p->isMember())
                        <p class="text-muted">Eres tú (o un miembro de la familia): aparece como <b>«Para mí»</b> al registrar gastos.</p>
                    @elseif($p->account)
                        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted">
                            <span class="chip bg-brand-soft text-brand"><x-icon name="check" class="size-3.5"/> Cuenta vinculada</span>
                            <span class="truncate">{{ $p->account->name }} · {{ $p->account->email }}</span>
                            <form method="POST" action="{{ route('people.unlink', $p) }}" class="inline" onsubmit="return confirm('¿Quitar el acceso de {{ $p->name }} a los gastos compartidos?')">@csrf @method('DELETE')<button class="font-bold text-muted hover:text-expense">Desvincular</button></form>
                        </p>
                    @elseif(isset($personInvites[$p->id]) && $personInvites[$p->id]->declined_at)
                        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted">
                            <span class="chip bg-expense/10 text-expense">Rechazó la solicitud</span>
                            <form method="POST" action="{{ route('family.revoke', $personInvites[$p->id]) }}" class="inline">@csrf @method('DELETE')<button class="font-bold text-brand">Quitar aviso e invitar de nuevo</button></form>
                        </p>
                    @elseif(isset($personInvites[$p->id]))
                        @php $pi = $personInvites[$p->id]; $hasAccount = $pi->email && in_array(mb_strtolower($pi->email), $existingEmails, true); @endphp
                        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted">
                            <span class="chip bg-warn/15 text-warn">{{ $hasAccount ? 'Solicitud enviada: debe aceptarla en su cuenta' : 'Invitación pendiente' }}</span>
                            <button type="button" class="font-bold text-brand" onclick="navigator.clipboard.writeText('{{ route('register', $pi->token) }}'); this.textContent='Enlace copiado'">Copiar enlace</button>
                            <form method="POST" action="{{ route('family.revoke', $pi) }}" class="inline">@csrf @method('DELETE')<button class="font-bold text-muted hover:text-expense">Cancelar</button></form>
                        </p>
                    @else
                        <button type="button" class="min-h-9 font-bold text-brand" @click="inv = !inv">Invitar a que tenga su cuenta</button>
                        <form x-show="inv" x-cloak method="POST" action="{{ route('people.invite', $p) }}" class="mt-1 grid gap-2 sm:grid-cols-[1fr_auto]">
                            @csrf
                            <input class="field" type="email" name="email" maxlength="120" value="{{ $p->email }}" placeholder="Su correo (opcional)" aria-label="Correo de {{ $p->name }}">
                            <button class="btn btn-primary !min-h-11">Crear invitación</button>
                            <p class="text-muted sm:col-span-2">Tendrá su propia cuenta y solo verá los gastos que le compartas (cuánto le toca, cuánto pagó y cuánto falta).</p>
                        </form>
                    @endif
                </div>
                <form x-show="edit" x-cloak method="POST" action="{{ route('people.update', $p) }}" class="mt-2 grid gap-2 sm:grid-cols-[1fr_11rem_auto]">
                    @csrf @method('PATCH')
                    <input class="field" name="name" value="{{ $p->name }}" maxlength="60" required aria-label="Nombre">
                    <input class="field sm:col-span-3" type="email" name="email" value="{{ $p->email }}" maxlength="120" placeholder="Correo (opcional)" aria-label="Correo">
                    <select class="field" name="relation" aria-label="Relación">
                        <option value="">Sin especificar</option>
                        @foreach(\App\Models\Person::selectable() as $k => $l)<option value="{{ $k }}" @selected($p->relation === $k)>{{ $l }}</option>@endforeach
                    </select>
                    <button class="btn btn-primary">Guardar</button>
                </form>
            </li>
        @empty
            <li class="py-3 text-sm text-muted">Aún no agregaste a nadie. Empieza con tus hijos o tu pareja.</li>
        @endforelse
    </ul>
    <form method="POST" action="{{ route('people.store') }}" class="mt-3 grid gap-2 rounded-xl bg-surface2 p-3 sm:grid-cols-[1fr_11rem]" x-data="{ mail: '' }">
        @csrf
        <h3 class="text-sm font-extrabold sm:col-span-2">Agregar una persona</h3>
        <div>
            <label class="label" for="pp-name">Nombre</label>
            <input id="pp-name" class="field" name="name" maxlength="60" required placeholder="Ej: María">
        </div>
        <div>
            <label class="label" for="pp-rel">Relación</label>
            <select id="pp-rel" class="field" name="relation">
                <option value="">Sin especificar</option>
                @foreach(\App\Models\Person::selectable() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="pp-email">Correo <span class="font-medium">(opcional)</span></label>
            <input id="pp-email" class="field" type="email" name="email" maxlength="120" autocomplete="off" placeholder="Para que vea los gastos que le compartas" x-model="mail">
            <label class="mt-2 flex min-h-10 items-start gap-2 text-sm" x-show="mail.trim() !== ''" x-cloak>
                <input type="checkbox" name="send_invite" value="1" class="mt-0.5 size-5" checked>
                <span><b>Invitarle ahora</b> a ver sus gastos compartidos. Si ya tiene cuenta, le aparecerá una solicitud dentro de la app.</span>
            </label>
        </div>
        <button class="btn btn-primary sm:col-span-2"><x-icon name="plus" class="size-4"/> Agregar</button>
    </form>
</section>

<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Avisos por correo</h2>
    <p class="text-sm text-muted">Alertas de presupuesto (80 % y 100 %), recordatorio de deudas por vencer y el resumen del día 1 de cada mes, a <b>{{ auth()->user()->email }}</b>.</p>
    <form method="POST" action="{{ route('preferences.update') }}" class="mt-3 flex items-center justify-between gap-3">
        @csrf @method('PATCH')
        <label class="flex min-h-11 items-center gap-3 text-sm font-bold">
            <input type="checkbox" name="notify_email" value="1" class="size-5" @checked(auth()->user()->notify_email)> Recibir avisos por correo
        </label>
        <button class="btn btn-ghost">Guardar</button>
    </form>
</section>

@if(auth()->user()->isOwner())
<section class="card mt-4 p-5" x-data="{ copied: false }">
    <h2 class="font-extrabold">Invitar a un familiar</h2>
    <p class="text-sm text-muted">Escribe su correo y le enviamos la invitación, o deja el correo vacío y comparte el enlace por WhatsApp. Vale 7 días y al registrarse queda dentro de tu familia.</p>
    <form method="POST" action="{{ route('family.invite') }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
        @csrf
        <input class="field" type="email" name="email" placeholder="Correo del familiar (opcional)" aria-label="Correo del familiar">
        <button class="btn btn-primary shrink-0"><x-icon name="plus"/> Invitar</button>
    </form>

    @if(session('link'))
        <div class="mt-4 rounded-xl border border-brand bg-brand-soft p-3">
            <p class="text-xs font-bold text-brand">Enlace listo para compartir</p>
            <div class="mt-1 flex items-center gap-2">
                <input readonly class="field !min-h-10 text-sm" value="{{ session('link') }}" id="invite-link" aria-label="Enlace de invitación">
                <button type="button" class="btn btn-primary !min-h-10 !px-3" @click="navigator.clipboard.writeText(document.getElementById('invite-link').value); copied = true" aria-label="Copiar enlace"><x-icon name="copy"/><span x-text="copied ? 'Copiado' : 'Copiar'"></span></button>
            </div>
            <a class="mt-2 inline-block text-sm font-bold text-brand underline" target="_blank" rel="noopener" href="https://wa.me/?text={{ urlencode('Te invito a nuestro presupuesto familiar: '.session('link')) }}">Enviar por WhatsApp</a>
        </div>
    @endif

    @if($invitations->isNotEmpty())
        <h3 class="mt-5 text-sm font-extrabold">Invitaciones pendientes</h3>
        <ul class="mt-1 divide-y divide-line">
            @foreach($invitations as $i)
                <li class="flex items-center justify-between gap-2 py-2 text-sm">
                    <span class="min-w-0 truncate">{{ $i->email ?: 'Enlace abierto' }} <span class="text-xs text-muted">· vence {{ $i->expires_at->diffForHumans() }}</span></span>
                    <div class="flex shrink-0 items-center gap-3">
                        <button type="button" class="font-bold text-brand" onclick="navigator.clipboard.writeText('{{ route('register', $i->token) }}'); this.textContent='Copiado'">Copiar</button>
                        <form method="POST" action="{{ route('family.revoke', $i) }}">@csrf @method('DELETE')<button class="text-xs font-bold text-muted hover:text-expense">Cancelar</button></form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endif

@if(ve_mode())
<section class="card mt-4 p-5">
    <h2 class="font-extrabold">Tasa de cambio</h2>
    @php $o = app(\App\Services\RateService::class)->latest('oficial'); $p = app(\App\Services\RateService::class)->latest('paralelo'); @endphp
    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
        <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Oficial (BCV)</dt><dd class="num font-extrabold">{{ $o ? 'Bs '.number_format($o->rate, 2, ',', '.') : '—' }}</dd></div>
        <div class="rounded-xl bg-surface2 p-3"><dt class="text-xs font-bold text-muted">Paralelo</dt><dd class="num font-extrabold">{{ $p ? 'Bs '.number_format($p->rate, 2, ',', '.') : '—' }}</dd></div>
    </dl>
    <p class="mt-2 text-xs text-muted">Los equivalentes en bolívares usan la tasa oficial. Si la consulta falla, puedes fijarla a mano:</p>
    <form method="POST" action="{{ route('rate.manual') }}" class="mt-2 flex gap-2">@csrf
        <input class="field num" name="rate" type="number" step="0.0001" min="0.0001" inputmode="decimal" placeholder="Bs por 1 USD" aria-label="Tasa manual Bs por USD" required>
        <button class="btn btn-ghost shrink-0">Fijar</button>
    </form>
</section>
@endif
</x-layouts.app>
