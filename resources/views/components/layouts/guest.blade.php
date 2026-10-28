<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.brand-head')
    <title>{{ $title ?? 'Entrar' }} · {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh">
<main class="mx-auto grid min-h-dvh w-full max-w-md content-center gap-6 px-4 py-10">
    <div class="text-center">
        <h1 class="mb-1 flex justify-center">
            <span class="sr-only">{{ config('app.name') }}</span>
            <img src="{{ asset('brand/logo.svg') }}" alt="" width="190" height="56" class="h-14 w-auto dark:hidden">
            <img src="{{ asset('brand/logo-dark.svg') }}" alt="" width="190" height="56" class="hidden h-14 w-auto dark:block">
        </h1>
        <p class="mt-1 text-sm text-muted">Tus gastos, metas y deudas, claras y en tu moneda.</p>
    </div>
    {{ $slot }}
</main>
</body>
</html>
