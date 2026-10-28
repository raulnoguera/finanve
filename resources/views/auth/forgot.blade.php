<x-layouts.guest title="Recuperar contraseña">
<form method="POST" action="{{ route('password.email') }}" class="card grid gap-4 p-5 rise">
    @csrf
    <div>
        <h2 class="text-lg font-extrabold">¿Olvidaste tu contraseña?</h2>
        <p class="mt-1 text-sm text-muted">Escribe tu correo y te enviamos un enlace para crear una nueva.</p>
    </div>
    @if(session('ok'))<p class="rounded-xl bg-brand-soft p-3 text-sm font-bold text-brand" role="status">{{ session('ok') }}</p>@endif
    <div>
        <label class="label" for="email">Correo</label>
        <input class="field" id="email" name="email" type="email" inputmode="email" autocomplete="email" value="{{ old('email') }}" required autofocus>
        @error('email')<p class="mt-1 text-sm text-expense" role="alert">{{ $message }}</p>@enderror
    </div>
    <button class="btn btn-primary">Enviarme el enlace</button>
    <p class="text-center text-sm"><a class="font-bold text-brand" href="{{ route('login') }}">Volver a entrar</a></p>
</form>
</x-layouts.guest>
