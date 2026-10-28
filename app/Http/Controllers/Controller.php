<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Services\RateService;
use Carbon\Carbon;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function household(Request $r): Household
    {
        return $r->user()->household;
    }

    /** Falla con 404 si el registro no pertenece a la familia del usuario. */
    protected function own(Request $r, $model)
    {
        abort_unless($model->household_id === $r->user()->household_id, 404);
        return $model;
    }

    /** "¿Dónde o para quién?": limpia espacios y reutiliza la escritura ya usada (Gama = gama). */
    protected function canonicalPlace(Request $r, ?string $place): ?string
    {
        $place = trim(preg_replace('/\s+/u', ' ', (string) $place));
        if ($place === '') {
            return null;
        }

        $place = mb_substr($place, 0, 60);

        return $this->household($r)->transactions()->whereNotNull('place')
            ->whereRaw('LOWER(place) = ?', [mb_strtolower($place)])->latest('id')->value('place') ?? $place;
    }

    /** Si el formulario envía person_id = "me", lo reemplaza por la persona "yo" del usuario. */
    protected function resolveMe(Request $r, string $key = 'person_id'): void
    {
        if ($r->input($key) === 'me') {
            $r->merge([$key => $this->household($r)->mePerson($r->user())->id]);
        }
    }

    protected function month(Request $r): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', (string) $r->query('mes', now()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    /** Pasa un monto a la moneda base de la familia. Solo Venezuela convierte (Bs → USD). Devuelve [monto, tasa|null]. */
    protected function toUsd(float $amount, string $currency): array
    {
        if ($currency !== 'VES') {
            return [$amount, null];
        }

        $rate = app(RateService::class)->current();
        abort_unless($rate, 422, 'No hay tasa de cambio disponible. Regístrala en la sección Familia.');

        return [round($amount / $rate, 2), $rate];
    }
}
