<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $t) {
            // Aporte a una meta: movimiento de tipo "saving" (ahorro), ligado a la meta
            $t->foreignId('goal_id')->nullable()->after('person_id')->constrained('goals')->nullOnDelete();
        });

        Schema::table('goal_contributions', function (Blueprint $t) {
            $t->foreignId('transaction_id')->nullable()->after('goal_id')->constrained('transactions')->cascadeOnDelete();
        });

        // Los aportes que ya existían pasan a verse también en Movimientos
        DB::table('goal_contributions')->whereNull('transaction_id')->orderBy('id')->each(function ($c) {
            $goal = DB::table('goals')->find($c->goal_id);
            if (! $goal) {
                return;
            }
            $country = DB::table('households')->where('id', $goal->household_id)->value('country');
            $currency = config("countries.$country.currency", 'USD');

            $txId = DB::table('transactions')->insertGetId([
                'household_id' => $goal->household_id, 'user_id' => $c->user_id, 'goal_id' => $goal->id,
                'type' => 'saving', 'amount' => $c->amount, 'currency' => $currency, 'original_amount' => $c->amount,
                'date' => $c->date, 'note' => 'Aporte a la meta: '.$goal->name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('goal_contributions')->where('id', $c->id)->update(['transaction_id' => $txId]);
        });
    }

    public function down(): void
    {
        DB::table('transactions')->where('type', 'saving')->delete();
        Schema::table('goal_contributions', fn (Blueprint $t) => $t->dropConstrainedForeignId('transaction_id'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('goal_id'));
    }
};
