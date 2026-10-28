<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Normaliza las personas con las que se comparte un gasto (una o varias) desde el formulario. */
class ShareInput
{
    /**
     * @param  array  $data  datos validados: shares[] {person_id, percent} y/o share_person_id + share_percent (formato antiguo)
     * @return array<int, array{person_id:int, percent:float}>
     */
    public static function parse(array $data): array
    {
        $rows = collect($data['shares'] ?? [])
            ->filter(fn ($s) => ! empty($s['person_id']) || ! empty($s['percent']));

        if ($rows->isEmpty() && ! empty($data['share_person_id'])) {
            $rows = collect([['person_id' => $data['share_person_id'], 'percent' => $data['share_percent'] ?? null]]);
        }

        $out = [];
        foreach ($rows as $s) {
            if (empty($s['person_id'])) {
                throw ValidationException::withMessages(['shares' => 'Elige la persona de cada porcentaje.']);
            }
            if (empty($s['percent']) || (float) $s['percent'] <= 0) {
                throw ValidationException::withMessages(['share_percent' => 'Indica el porcentaje que paga cada persona.']);
            }
            $id = (int) $s['person_id'];
            if (isset($out[$id])) {
                throw ValidationException::withMessages(['shares' => 'No repitas a la misma persona.']);
            }
            $out[$id] = ['person_id' => $id, 'percent' => round((float) $s['percent'], 2)];
        }

        if (array_sum(array_column($out, 'percent')) > 100.001) {
            throw ValidationException::withMessages(['shares' => 'Los porcentajes de las personas suman más de 100 %.']);
        }

        return array_values($out);
    }
}
