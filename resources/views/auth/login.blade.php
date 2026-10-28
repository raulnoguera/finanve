<x-layouts.guest title="Entrar">
<form method="POST" action="{{ url('/entrar') }}" class="card grid gap-4 p-5 rise">
    @csrf
    @if(session('ok'))<p class="rounded-xl bg-brand-soft p-3 text-sm font-bold text-brand" role="status">{{ session('ok') }}</p>@endif
    <div>
        <label class="label" for="email">Correo</label>
        <input class="field" id="email" name="email" type="email" autocomplete="email" inputmode="email" value="{{ old('email') }}" required autofocus>
        @error('email')<p class="mt-1 text-sm text-expense" role="alert">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label" for="password">Contraseña</label>
        <input class="field" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <div class="flex items-center justify-between gap-2">
        <label class="flex items-center gap-2 text-sm text-muted"><input type="checkbox" name="remember" class="size-4"> Mantener sesión</label>
        <a class="text-sm font-bold text-brand" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
    </div>
    <button class="btn btn-primary">Entrar</button>
    <p class="text-center text-sm text-muted">¿Primera vez? <a class="font-bold text-brand" href="{{ route('register') }}">Crear cuenta</a></p>
</form>
</x-layouts.guest>
