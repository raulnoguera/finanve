<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    protected $guarded = [];
    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'declined_at' => 'datetime'];

    public static function issue(Household $household, User $by, ?string $email = null): self
    {
        return static::create([
            'household_id' => $household->id,
            'invited_by' => $by->id,
            'email' => $email,
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public static function issueForPerson(Person $person, User $by, ?string $email = null): self
    {
        return static::create([
            'household_id' => $person->household_id,
            'person_id' => $person->id,
            'invited_by' => $by->id,
            'email' => $email,
            'token' => Str::random(48),
            'expires_at' => now()->addDays(14),
        ]);
    }

    /** Solicitudes de compartir gastos dirigidas a la cuenta de este usuario (por correo coincidente). */
    public function scopePendingFor($q, User $u)
    {
        return $q->whereNotNull('person_id')->whereNull('accepted_at')->whereNull('declined_at')
            ->where('expires_at', '>', now())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($u->email)])
            ->where('household_id', '!=', $u->household_id)
            ->whereHas('person', fn ($p) => $p->whereNull('user_id'));
    }

    /** Vincula a la persona con la cuenta del usuario. Devuelve un mensaje de error o null si todo salió bien. */
    public function acceptFor(User $user): ?string
    {
        $this->loadMissing('person');

        if (! $this->person_id || ! $this->isUsable() || $this->declined_at) {
            return 'Esta invitación no es válida o ya venció. Pide una nueva.';
        }
        if ($user->household_id === $this->household_id) {
            return 'Ya formas parte de esa familia: ves todos sus gastos directamente.';
        }
        if ($this->person->user_id && $this->person->user_id !== $user->id) {
            return 'Esta invitación ya fue usada por otra cuenta.';
        }

        $this->person->update(['user_id' => $user->id]);
        $this->update(['accepted_at' => now()]);

        return null;
    }

    public function isParticipant(): bool { return $this->person_id !== null; }
    public function person(): BelongsTo { return $this->belongsTo(Person::class); }
    public function household(): BelongsTo { return $this->belongsTo(Household::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
