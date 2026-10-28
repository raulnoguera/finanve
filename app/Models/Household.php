<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $guarded = [];

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function categories(): HasMany { return $this->hasMany(Category::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
    public function goals(): HasMany { return $this->hasMany(Goal::class); }
    public function debts(): HasMany { return $this->hasMany(Debt::class); }
    public function people(): HasMany { return $this->hasMany(Person::class); }
    /** La persona "yo" del usuario: permite elegir "Para mí" en gastos y gastos fijos. */
    public function mePerson(User $user): Person
    {
        return $this->people()->firstOrCreate(
            ['member_user_id' => $user->id],
            ['name' => $user->name, 'relation' => 'yo', 'color' => Person::colorFor($user->name)],
        );
    }

    public function fixedExpenses(): HasMany { return $this->hasMany(FixedExpense::class); }
    public function invitations(): HasMany { return $this->hasMany(Invitation::class); }

    /** Moneda en que la familia lleva sus cuentas. */
    public function currency(): string
    {
        return config("countries.{$this->country}.currency", 'USD');
    }

    /** Subcategorías sugeridas por categoría de gasto (idempotente: solo agrega las que faltan). */
    public const SUGGESTED_SUBCATEGORIES = [
        'Comida' => ['Restaurantes', 'Delivery', 'Café y snacks'],
        'Mercado' => ['Víveres', 'Carnes y pollo', 'Frutas y verduras', 'Aseo y limpieza'],
        'Transporte' => ['Combustible', 'Transporte público', 'Mantenimiento'],
        'Vivienda' => ['Alquiler o hipoteca', 'Condominio', 'Reparaciones'],
        'Servicios' => ['Electricidad', 'Agua', 'Internet y teléfono'],
        'Salud' => ['Médico', 'Medicinas', 'Seguro'],
        'Educación' => ['Colegio', 'Cursos', 'Útiles y uniformes'],
        'Ocio' => ['Salidas', 'Suscripciones', 'Viajes'],
    ];

    /** Subcategorías de ingreso sugeridas. */
    public const SUGGESTED_INCOME_SUBCATEGORIES = [
        'Salario' => ['Quincena', 'Bono', 'Horas extra', 'Utilidades'],
        'Otros ingresos' => ['Ventas', 'Remesas', 'Alquileres', 'Regalos'],
    ];

    /** Agrega las subcategorías sugeridas que falten (tipo: expense | income | null = ambos). Devuelve cuántas creó. */
    public function seedSubcategories(?string $type = null): int
    {
        $added = 0;
        $sets = ['expense' => self::SUGGESTED_SUBCATEGORIES, 'income' => self::SUGGESTED_INCOME_SUBCATEGORIES];

        foreach ($sets as $t => $map) {
            if ($type && $type !== $t) {
                continue;
            }
            foreach ($map as $parentName => $children) {
                $parent = $this->categories()->where('type', $t)->whereNull('parent_id')->where('name', $parentName)->first();
                if (! $parent) {
                    continue;
                }
                foreach ($children as $name) {
                    if (! $this->categories()->where('parent_id', $parent->id)->where('name', $name)->exists()) {
                        $this->categories()->create(['parent_id' => $parent->id, 'name' => $name, 'type' => $t, 'icon' => $parent->icon, 'color' => $parent->color]);
                        $added++;
                    }
                }
            }
        }

        return $added;
    }

    public function seedCategories(): void
    {
        $defaults = [
            ['Salario', 'income', 'briefcase', '#10b981'],
            ['Otros ingresos', 'income', 'plus-circle', '#14b8a6'],
            ['Comida', 'expense', 'utensils', '#f97316'],
            ['Mercado', 'expense', 'shopping-cart', '#f59e0b'],
            ['Transporte', 'expense', 'car', '#3b82f6'],
            ['Vivienda', 'expense', 'home', '#8b5cf6'],
            ['Servicios', 'expense', 'zap', '#06b6d4'],
            ['Salud', 'expense', 'heart', '#ef4444'],
            ['Educación', 'expense', 'book', '#6366f1'],
            ['Ocio', 'expense', 'smile', '#ec4899'],
            ['Otros', 'expense', 'tag', '#64748b'],
        ];

        foreach ($defaults as [$name, $type, $icon, $color]) {
            $this->categories()->create(compact('name', 'type', 'icon', 'color'));
        }

        $this->seedSubcategories();
    }
}
