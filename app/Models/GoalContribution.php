<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalContribution extends Model
{
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
