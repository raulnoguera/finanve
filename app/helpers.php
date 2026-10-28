<?php

use App\Services\RateService;

if (! function_exists('ve_mode')) {
    /** ¿La familia del usuario usa la app desde un país con equivalente en Bs? */
    function ve_mode(): bool
    {
        $c = auth()->user()?->household?->country;

        return (bool) config("countries.$c.ves", false);
    }
}

if (! function_exists('rate')) {
    /** Tasa vigente Bs/USD (memoizada por request); null fuera de Venezuela. */
    function rate(): ?float
    {
        if (! ve_mode()) {
            return null;
        }

        static $r = false;

        return $r === false ? ($r = app(RateService::class)->current()) : $r;
    }
}

if (! function_exists('base_currency')) {
    /** Código de la moneda en que la familia lleva sus cuentas (USD, COP, EUR, CLP). */
    function base_currency(): string
    {
        $c = auth()->user()?->household?->country;

        return config("countries.$c.currency", 'USD');
    }
}

if (! function_exists('cur')) {
    function cur(): string
    {
        return base_currency();
    }
}

if (! function_exists('currency_codes')) {
    /** Monedas aceptables al registrar un monto: la base y, en Venezuela, también bolívares. */
    function currency_codes(): array
    {
        return ve_mode() ? ['USD', 'VES'] : [base_currency()];
    }
}

if (! function_exists('money')) {
    /** Formatea un monto en la moneda base de la familia. */
    function money(float|int|string|null $n, bool $sign = false): string
    {
        return \App\Support\Money::format($n, base_currency(), $sign);
    }
}

if (! function_exists('usd')) {
    /** Compatibilidad: formatea en la moneda base (históricamente era siempre USD). */
    function usd(float|int|string|null $n, bool $sign = false): string
    {
        return money($n, $sign);
    }
}

if (! function_exists('ves')) {
    /** Bs equivalente de un monto USD, o null si no hay tasa. */
    function ves(float|int|string|null $usd): ?string
    {
        $r = rate();

        if (! $r) {
            return null;
        }

        $v = (float) $usd * $r;

        return ($v < 0 ? '−' : '').'Bs '.number_format(abs($v), 2, ',', '.');
    }
}

if (! function_exists('mes_es')) {
    function mes_es(\Carbon\Carbon $d): string
    {
        $m = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $m[$d->month - 1].' '.$d->year;
    }
}
