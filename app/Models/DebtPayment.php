<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtPayment extends Model
{
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function item(): BelongsTo { return $this->belongsTo(DebtItem::class, 'debt_item_id'); }
    public function debt(): BelongsTo { return $this->belongsTo(Debt::class); }
    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reported_by'); }
}
