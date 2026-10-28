<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = [];
    protected $casts = ['monthly_budget' => 'decimal:2'];

    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
    public function household(): BelongsTo { return $this->belongsTo(Household::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id')->orderBy('name'); }

    public function isSub(): bool { return $this->parent_id !== null; }

    /** "Comida › Restaurantes" (o solo el nombre si es de primer nivel). */
    public function fullName(): string
    {
        return $this->parent_id ? $this->parent->name.' › '.$this->name : $this->name;
    }
}
