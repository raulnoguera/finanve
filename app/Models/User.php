<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'household_id', 'role', 'notify_email'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'notify_email' => 'boolean'];
    }

    /** Personas de otras familias vinculadas a esta cuenta (gastos que le comparten). */
    public function linkedPeople(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Person::class, 'user_id'); }

    public function household(): BelongsTo { return $this->belongsTo(Household::class); }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordEs($token));
    }

    public function isOwner(): bool { return $this->role === 'owner'; }
}
