<x-layouts.guest title="Elige tu país">
<section class="rise">
    <h2 class="text-center text-xl font-extrabold tracking-tight">¿Desde qué país vas a usar Finanve?</h2>
    <p class="mt-1 text-center text-sm text-muted">Así ajustamos la app a tu moneda y tu realidad.</p>
    <div class="mt-5 grid gap-3" role="list">
        @foreach($countries as $code => $c)
            <a href="{{ route('register', ['pais' => $code]) }}" role="listitem"
               class="card group flex min-h-16 items-center gap-4 p-3 pr-4 transition hover:border-brand hover:shadow-md focus-visible:border-brand">
                <x-flag :code="$code" class="h-10 w-[60px] shrink-0 rounded-md shadow-sm"/>
                <span class="flex-1 text-base font-extrabold">{{ $c['name'] }}</span>
                <x-icon name="chev-right" class="size-5 text-muted transition group-hover:translate-x-0.5 group-hover:text-brand"/>
            </a>
        @endforeach
    </div>
    <p class="mt-6 text-center text-sm text-muted">¿Ya tienes cuenta? <a class="font-bold text-brand" href="{{ route('login') }}">Entrar</a></p>
</section>
</x-layouts.guest>
