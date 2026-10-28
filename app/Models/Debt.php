<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Debt extends Model
{
    protected $guarded = [];
    protected $casts = ['fixed_due' => 'date:Y-m-d', 'due_date' => 'date', 'settled_at' => 'datetime', 'amount' => 'decimal:2', 'percent' => 'decimal:2'];

    public function items(): HasMany { return $this->hasMany(DebtItem::class)->orderBy('position')->orderBy('id'); }

    /** Suma de los detalles (desglose) y lo que aún no está detallado del total. */
    public function detailedTotal(): float
    {
        return (float) ($this->relationLoaded('items') ? $this->items->sum('amount') : $this->items()->sum('amount'));
    }

    public function undetailed(): float { return max(0.0, (float) $this->amount - $this->detailedTotal()); }

    public function payments(): HasMany { return $this->hasMany(DebtPayment::class)->latest('date'); }
    public function fixedExpense(): BelongsTo { return $this->belongsTo(FixedExpense::class)->withTrashed(); }

    /** Excluye las cuotas programadas que el titular aún no ha pagado (no son "me deben" todavía). */
    public function scopeIncurred($q)
    {
        return $q->where(fn ($w) => $w->whereNull('fixed_expense_id')->orWhereNotNull('transaction_id'));
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class)->withoutGlobalScopes(); }
    /** Persona de la lista a la que corresponde esta parte (la columna `person` guarda solo el nombre). */
    public function linkedPerson(): BelongsTo { return $this->belongsTo(Person::class, 'person_id'); }

    public function paid(): float
    {
        return (float) ($this->relationLoaded('payments') ? $this->payments->sum('amount') : $this->payments()->sum('amount'));
    }

    public function remaining(): float { return max(0, (float) $this->amount - $this->paid()); }

    /** pending | partial | paid */
    public function shareStatus(): string
    {
        if ($this->remaining() <= 0.004) {
            return 'paid';
        }

        return $this->paid() > 0 ? 'partial' : 'pending';
    }
}
