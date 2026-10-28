<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DebtItem extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2'];

    public function debt(): BelongsTo { return $this->belongsTo(Debt::class); }
    public function payment(): HasOne { return $this->hasOne(DebtPayment::class); }

    public function isPaid(): bool
    {
        return $this->relationLoaded('payment') ? $this->payment !== null : $this->payment()->exists();
    }
}
