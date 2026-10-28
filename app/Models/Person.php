<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $table = 'people';
    protected $guarded = [];

    public const RELATIONS = [
        'hijo' => 'Hijo/a', 'hermano' => 'Hermano/a', 'pareja' => 'Pareja', 'padre' => 'Mamá o papá', 'mascota' => 'Mascota', 'otro' => 'Otro', 'yo' => 'Miembro de la familia',
    ];
    public const PALETTE = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#0ea5e9', '#8b5cf6', '#f97316', '#14b8a6'];

    public function household(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Household::class); }
    /** Cuenta vinculada (si la persona ya tiene su propio usuario). */
    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }

    /** Relaciones que se pueden elegir al agregar o editar (la de "yo" la gestiona la app). */
    public static function selectable(): array
    {
        return array_diff_key(self::RELATIONS, ['yo' => true]);
    }

    public function isMember(): bool { return $this->member_user_id !== null; }

    /** Nombre para formularios: "Para mí" si es la persona del propio usuario. */
    public function formLabel(?int $viewerId): string
    {
        return $this->member_user_id && $this->member_user_id === $viewerId ? 'Para mí' : $this->name;
    }

    public function relationLabel(): ?string { return self::RELATIONS[$this->relation] ?? null; }

    public static function colorFor(string $name): string
    {
        return self::PALETTE[crc32(mb_strtolower($name)) % count(self::PALETTE)];
    }
}
