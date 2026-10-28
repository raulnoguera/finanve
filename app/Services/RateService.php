<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Tasa de cambio Bs por 1 USD (Venezuela). Fuente: ve.dolarapi.com
 * (oficial = BCV, paralelo). Se guarda histórico en BD para no depender de la API.
 */
class RateService
{
    private const URL = 'https://ve.dolarapi.com/v1/dolares/';
    private const TTL_HOURS = 4;

    /** Tasa vigente (Bs por USD) o null si nunca se pudo obtener. */
    public function current(string $source = 'oficial'): ?float
    {
        $row = $this->latest($source);

        if (! $row || $row->fetched_at->lt(now()->subHours(self::TTL_HOURS))) {
            // Evita martillar la API si falla: reintenta como máximo cada 10 min.
            Cache::remember("rate-refresh-attempt-{$source}", now()->addMinutes(10), function () use ($source) {
                $this->refresh($source);
                return true;
            });
            $row = $this->latest($source);
        }

        return $row ? (float) $row->rate : null;
    }

    public function latest(string $source = 'oficial'): ?ExchangeRate
    {
        // La tasa fijada a mano pisa a la automática si es más reciente.
        $sources = $source === 'oficial' ? ['oficial', 'manual'] : [$source];

        return ExchangeRate::whereIn('source', $sources)->latest('fetched_at')->latest('id')->first();
    }

    /** Consulta la API y guarda la tasa. Devuelve true si funcionó. */
    public function refresh(string $source = 'oficial'): bool
    {
        try {
            $res = Http::timeout(8)->acceptJson()->get(self::URL.$source);
            $rate = (float) ($res->json('promedio') ?? 0);

            if ($res->ok() && $rate > 0) {
                ExchangeRate::create(['source' => $source, 'rate' => $rate, 'fetched_at' => now()]);
                return true;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return false;
    }

    public function setManual(float $rate): void
    {
        ExchangeRate::create(['source' => 'manual', 'rate' => $rate, 'fetched_at' => now()]);
    }

    /** Convierte USD a Bs con la tasa vigente. */
    public function toVes(float $usd, ?float $rate = null): ?float
    {
        $rate ??= $this->current();
        return $rate ? $usd * $rate : null;
    }
}
