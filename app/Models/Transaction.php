<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $guarded = [];
    protected $casts = ['items' => 'array', 'total_amount' => 'decimal:2', 'fixed_due' => 'date:Y-m-d', 'date' => 'date', 'amount' => 'decimal:2', 'original_amount' => 'decimal:2', 'rate' => 'decimal:4'];

    protected static function booted(): void
    {
        // Los gastos "por pagar" no cuentan en saldos, presupuestos ni reportes hasta confirmar el pago
        static::addGlobalScope('paid', fn ($q) => $q->where($q->getModel()->getTable().'.status', 'paid'));
    }

    /** Los enlaces de rutas encuentran también los gastos por pagar (para confirmarlos o borrarlos). */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQueryWithoutScope('paid')->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function isPending(): bool { return $this->status === 'pending'; }

    /** Partes de este gasto que otras personas deben (seguimiento como "Me deben"). */
    public function shares(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Debt::class, 'transaction_id'); }
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
    public function person(): BelongsTo { return $this->belongsTo(Person::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
