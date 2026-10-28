<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Datos de ejemplo para probar en local: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $h = Household::create(['name' => 'Familia Demo']);
        $h->seedCategories();
        $u = User::create(['name' => 'Demo Usuario', 'email' => 'demo@example.test', 'password' => 'demo-pass-123', 'household_id' => $h->id, 'role' => 'owner']);
        $cat = fn ($n) => $h->categories()->where('name', $n)->value('id');

        foreach ([0, 1, 2] as $back) {
            $m = now()->subMonths($back)->startOfMonth();
            $h->transactions()->create(['user_id' => $u->id, 'type' => 'income', 'category_id' => $cat('Salario'), 'amount' => 1200, 'original_amount' => 1200, 'currency' => 'USD', 'date' => $m->copy()->addDays(1)]);
            foreach ([['Mercado', 260], ['Comida', 140], ['Transporte', 70], ['Servicios', 55], ['Vivienda', 300], ['Ocio', 60 + $back * 10]] as $i => [$c, $a]) {
                $d = $m->copy()->addDays(2 + $i * 3);
                if ($d->isFuture()) { $d = now(); }
                $h->transactions()->create(['user_id' => $u->id, 'type' => 'expense', 'category_id' => $cat($c), 'amount' => $a, 'original_amount' => $a, 'currency' => 'USD', 'date' => $d]);
            }
        }
        $h->categories()->where('name', 'Mercado')->update(['monthly_budget' => 250]);
        $h->categories()->where('name', 'Ocio')->update(['monthly_budget' => 100]);
        $h->categories()->where('name', 'Comida')->update(['monthly_budget' => 200]);

        $g = $h->goals()->create(['name' => 'Viaje a Mérida', 'target_amount' => 900, 'deadline' => now()->addMonths(6)]);
        $g->contributions()->create(['user_id' => $u->id, 'amount' => 200, 'date' => now()->subMonth()]);
        $h->goals()->create(['name' => 'Carro', 'target_amount' => 8000]);
        $h->debts()->create(['user_id' => $u->id, 'kind' => 'owe', 'person' => 'Banco', 'amount' => 400, 'due_date' => now()->addDays(20)]);
        $h->debts()->create(['user_id' => $u->id, 'kind' => 'lent', 'person' => 'Carlos', 'amount' => 80, 'due_date' => now()->subDays(3), 'note' => 'Préstamo para repuestos']);
    }
}
