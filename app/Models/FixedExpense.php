<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class FixedExpense extends Model
{
    use SoftDeletes;

    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'ended_at' => 'date', 'active' => 'boolean', 'start_date' => 'date', 'share_percent' => 'decimal:2', 'shares' => 'array',
        'interval_count' => 'integer', 'installments' => 'integer', 'duration_months' => 'integer', 'repeat_months' => 'integer',
    ];

    /** Opciones de frecuencia del formulario => [unidad, cada cuántos]. "days" usa el número que escribe la persona. */
    public const FREQUENCIES = [
        'monthly' => ['Cada mes', 'month', 1],
        'bimonthly' => ['Cada 2 meses', 'month', 2],
        'quarterly' => ['Trimestral (cada 3 meses)', 'month', 3],
        'cuatrimestral' => ['Cuatrimestral (cada 4 meses)', 'month', 4],
        'semestral' => ['Semestral (cada 6 meses)', 'month', 6],
        'annual' => ['Anual', 'month', 12],
        'days' => ['Cada cierto número de días', 'day', null],
    ];

    public function debts(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Debt::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function person(): BelongsTo { return $this->belongsTo(Person::class); }
    public function sharePerson(): BelongsTo { return $this->belongsTo(Person::class, 'share_person_id'); }

    /** Personas con las que se comparte cada cuota: [['person_id' => int, 'percent' => float], ...]. */
    public function participants(): array
    {
        if (! empty($this->shares)) {
            return collect($this->shares)->map(fn ($s) => ['person_id' => (int) $s['person_id'], 'percent' => (float) $s['percent']])->values()->all();
        }

        return $this->share_person_id && $this->share_percent
            ? [['person_id' => (int) $this->share_person_id, 'percent' => (float) $this->share_percent]]
            : [];
    }

    /** Pago repartido: cada quien paga su parte, así que tu gasto es solo tu porcentaje. */
    public function isSplitShared(): bool
    {
        return $this->share_person_id && $this->share_percent && $this->share_mode !== 'reimburse';
    }

    /** Monto de la cuota n de `of`: con "monto total" la última absorbe los centavos de redondeo. */
    public function cuotaAmount(?int $n = null, ?int $of = null): float
    {
        if ($this->total_amount && $of && $n === $of) {
            return round((float) $this->total_amount - round((float) $this->amount, 2) * ($of - 1), 2);
        }

        return round((float) $this->amount, 2);
    }

    /** Lo que te corresponde pagar de cada cuota (tu parte si se reparte; el total si no). */
    public function myAmount(?float $total = null): float
    {
        $total ??= (float) $this->amount;

        return $this->isSplitShared() ? round($total * (100 - (float) $this->share_percent) / 100, 2) : round($total, 2);
    }

    /** Mensual "de siempre": un vencimiento por mes en el día configurado. */
    public function isMonthly(): bool
    {
        return $this->interval_unit === 'month' && (int) $this->interval_count === 1 && ! $this->start_date;
    }

    /** Clave de formulario (monthly, quarterly, days…). */
    public function frequencyKey(): string
    {
        if ($this->interval_unit === 'day') {
            return 'days';
        }
        foreach (self::FREQUENCIES as $k => [$label, $unit, $n]) {
            if ($unit === 'month' && $n === (int) $this->interval_count) {
                return $k;
            }
        }

        return 'monthly';
    }

    public function frequencyLabel(): string
    {
        if ($this->interval_unit === 'day') {
            return $this->interval_count === 1 ? 'Todos los días' : "Cada {$this->interval_count} días";
        }

        return match ((int) $this->interval_count) {
            1 => 'Cada mes', 2 => 'Cada 2 meses', 3 => 'Trimestral', 4 => 'Cuatrimestral', 6 => 'Semestral', 12 => 'Anual',
            default => "Cada {$this->interval_count} meses",
        };
    }

    /** "durante 4 meses · se repite" (vacío si no hay periodo). */
    public function termLabel(): ?string
    {
        if (! $this->duration_months) {
            return null;
        }

        return 'durante '.$this->duration_months.($this->duration_months === 1 ? ' mes' : ' meses').($this->repeat_months ? ' · se repite' : '');
    }

    /** Fecha del vencimiento n (0-based) dentro de una serie que empieza en $from. */
    private function step(Carbon $from, int $n): Carbon
    {
        return $this->interval_unit === 'day'
            ? $from->copy()->addDays($n * $this->interval_count)
            : $from->copy()->addMonthsNoOverflow($n * $this->interval_count);
    }

    /**
     * Vencimientos que caen entre dos fechas (ambas incluidas).
     * Devuelve filas ['due' => Carbon, 'n' => nº de cuota, 'of' => cuotas del periodo (o null)].
     */
    public function occurrencesBetween(Carbon $from, Carbon $to): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $out = collect();

        if ($this->isMonthly()) {
            $m = $from->copy()->startOfMonth();
            while ($m->lte($to)) {
                $d = $m->copy()->day(min((int) $this->due_day, $m->daysInMonth));
                if ($d->betweenIncluded($from, $to)) {
                    $out->push(['due' => $d, 'n' => null, 'of' => null]);
                }
                $m = $m->copy()->addMonthNoOverflow();
            }

            return $out;
        }

        $anchor = ($this->start_date ?? $this->created_at)->copy()->startOfDay();
        $max = $this->installments ?: 600;

        // Con periodo (p. ej. 4 meses con cuotas cada 21 días), opcionalmente repetido cada N meses
        if ($this->duration_months) {
            $terms = $this->repeat_months ? 120 : 1;

            for ($k = 0; $k < $terms; $k++) {
                $termStart = $anchor->copy()->addMonthsNoOverflow($k * (int) $this->repeat_months);
                if ($termStart->gt($to)) {
                    break;
                }
                $termEnd = $termStart->copy()->addMonthsNoOverflow($this->duration_months);
                if ($termEnd->lte($from)) {
                    continue;
                }

                $dates = [];
                for ($n = 0; $n < $max; $n++) {
                    $d = $this->step($termStart, $n);
                    if ($d->gte($termEnd)) {
                        break;
                    }
                    $dates[] = $d;
                }
                foreach ($dates as $i => $d) {
                    if ($d->betweenIncluded($from, $to)) {
                        $out->push(['due' => $d, 'n' => $i + 1, 'of' => count($dates)]);
                    }
                }
            }

            return $out;
        }

        for ($n = 0; $n < $max; $n++) {
            $d = $this->step($anchor, $n);
            if ($d->gt($to)) {
                break;
            }
            if ($d->gte($from)) {
                $out->push(['due' => $d, 'n' => $n + 1, 'of' => $this->installments]);
            }
        }

        return $out;
    }

    /** Un gasto con fin: tiene número de cuotas o un periodo de meses (los mensuales sin tope no terminan). */
    public function isFinite(): bool
    {
        return ! $this->isMonthly() && ($this->installments || $this->duration_months);
    }

    /**
     * Calendario completo de cuotas del periodo vigente (el que aún no termina; si ya acabó, el último).
     * Filas ['due' => Carbon, 'n' => int, 'of' => int]. Vacío si no tiene fin definido.
     */
    public function schedule(?Carbon $ref = null): Collection
    {
        if (! $this->isFinite()) {
            return collect();
        }

        $ref = ($ref ?? today())->copy()->startOfDay();
        $anchor = ($this->start_date ?? $this->created_at)->copy()->startOfDay();
        $from = $anchor;
        $to = $anchor->copy()->addYears(10);

        if ($this->duration_months && $this->repeat_months) {
            // Periodo repetido: el que contiene a $ref (o el siguiente)
            for ($k = 0; $k < 120; $k++) {
                $start = $anchor->copy()->addMonthsNoOverflow($k * (int) $this->repeat_months);
                $end = $start->copy()->addMonthsNoOverflow($this->duration_months);
                if ($end->gt($ref) || $k === 119) {
                    $from = $start;
                    $to = $end->copy()->subDay();
                    break;
                }
            }
        } elseif ($this->duration_months) {
            $to = $anchor->copy()->addMonthsNoOverflow($this->duration_months)->subDay();
        }

        return $this->occurrencesBetween($from, $to)->values();
    }

    /** Fecha de la última cuota del periodo vigente (null si no termina). */
    public function endsOn(?Carbon $ref = null): ?Carbon
    {
        return $this->schedule($ref)->last()['due'] ?? null;
    }
}
