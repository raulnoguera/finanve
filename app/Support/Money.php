<?php

namespace App\Support;

class Money
{
    /** Formatea un monto en una moneda concreta (USD, COP, EUR, CLP, MXN). */
    public static function format(float|int|string|null $n, string $currency = 'USD', bool $sign = false): string
    {
        $n = (float) $n;
        $f = config("currencies.$currency", config('currencies.USD'));
        $num = number_format(abs($n), $f['decimals'], $f['dec'], $f['thou']);
        $txt = $f['after'] ? $num.' '.$f['symbol'] : $f['symbol'].$num;

        return ($sign && $n > 0 ? '+' : '').($n < 0 ? '−' : '').$txt;
    }
}
