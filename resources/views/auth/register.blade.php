<x-layouts.guest title="Crear cuenta">
@if($invitation && $invitation->isParticipant())
    <div class="card flex items-start gap-3 p-4 rise" style="background:var(--brand-soft)">
        <x-icon name="share" class="mt-0.5 size-5 text-brand"/>
        <p class="text-sm"><b>{{ $invitation->inviter->name }}</b> quiere compartir gastos contigo. Tendrás tu <b>propia cuenta</b> y solo verás lo que te comparta: cuánto te toca y cuánto has pagado.
            <br><span class="text-muted">¿Ya tienes cuenta? <a class="font-bold text-brand underline" href="{{ route('share.accept', $invitation->token) }}">Entra y acepta aquí</a>.</span></p>
    </div>
@elseif($invitation)
    <div class="card flex items-start gap-3 p-4 rise" style="background:var(--brand-soft)">
        <x-icon name="users" class="mt-0.5 size-5 text-brand"/>
        <p class="text-sm"><b>{{ $invitation->inviter->name }}</b> te invitó a unirte a <b>{{ $invitation->household->name }}</b>. Crea tu cuenta para compartir el presupuesto familiar.</p>
    </div>
@endif
<form method="POST" action="{{ route('register.store') }}" class="card grid gap-4 p-5 rise">
    @csrf
    <input type="hidden" name="token" value="{{ $invitation?->token }}">
    @unless($invitation)
        <input type="hidden" name="country" value="{{ $country }}">
        <div class="flex items-center gap-3 rounded-xl bg-surface2 p-2.5">
            <x-flag :code="$country" class="h-8 w-12 shrink-0 rounded"/>
            <div class="min-w-0 flex-1 leading-tight"><p class="text-xs font-bold text-muted">Usarás Finanve desde</p><p class="truncate font-extrabold">{{ config('countries.'.$country.'.name') }}</p></div>
            <a href="{{ route('register') }}" class="text-sm font-bold text-brand">Cambiar</a>
        </div>
        @error('country')<p class="text-sm text-expense" role="alert">{{ $message }}</p>@enderror
    @endunless
    @error('token')<p class="text-sm text-expense" role="alert">{{ $message }}</p>@enderror
    <div>
        <label class="label" for="name">Nombre</label>
        <input class="field" id="name" name="name" autocomplete="name" value="{{ old('name') }}" required>
        @error('name')<p class="mt-1 text-sm text-expense">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label" for="email">Correo</label>
        <input class="field" id="email" name="email" type="email" inputmode="email" autocomplete="email" value="{{ old('email', $invitation?->email) }}" required>
        @error('email')<p class="mt-1 text-sm text-expense">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label" for="password">Contraseña <span class="font-medium">(mínimo 8 caracteres)</span></label>
        <input class="field" id="password" name="password" type="password" autocomplete="new-password" required>
        @error('password')<p class="mt-1 text-sm text-expense">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label" for="password_confirmation">Repite la contraseña</label>
        <input class="field" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
    </div>
    <button class="btn btn-primary">{{ $invitation && ! $invitation->isParticipant() ? 'Unirme a la familia' : 'Crear mi cuenta' }}</button>
    <p class="text-center text-sm text-muted">¿Ya tienes cuenta? <a class="font-bold text-brand" href="{{ route('login') }}">Entrar</a></p>
</form>
</x-layouts.guest>
