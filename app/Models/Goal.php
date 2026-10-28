<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    protected $guarded = [];
    protected $casts = ['deadline' => 'date', 'completed_at' => 'datetime', 'target_amount' => 'decimal:2'];

    public function household(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Household::class); }

    public function contributions(): HasMany { return $this->hasMany(GoalContribution::class)->latest('date'); }

    public function savedAmount(): float
    {
        return (float) $this->contributions()->sum('amount');
    }
}
