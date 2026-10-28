<x-layouts.guest title="Nueva contraseña">
<form method="POST" action="{{ route('password.update') }}" class="card grid gap-4 p-5 rise">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <h2 class="text-lg font-extrabold">Crea tu nueva contraseña</h2>
    @error('email')<p class="rounded-xl bg-expense/10 p-3 text-sm font-bold text-expense" role="alert">{{ $message }}</p>@enderror
    <div>
        <label class="label" for="email">Correo</label>
        <input class="field" id="email" name="email" type="email" autocomplete="email" value="{{ old('email', $email) }}" required>
    </div>
    <div>
        <label class="label" for="password">Nueva contraseña <span class="font-medium">(mínimo 8 caracteres)</span></label>
        <input class="field" id="password" name="password" type="password" autocomplete="new-password" required autofocus>
        @error('password')<p class="mt-1 text-sm text-expense">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label" for="password_confirmation">Repite la contraseña</label>
        <input class="field" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
    </div>
    <button class="btn btn-primary">Guardar contraseña</button>
</form>
</x-layouts.guest>
