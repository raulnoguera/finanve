<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_expenses', function (Blueprint $t) {
            // Total del gasto cuando se indicó "monto total": `amount` guarda entonces el valor de cada cuota.
            $t->decimal('total_amount', 14, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_expenses', fn (Blueprint $t) => $t->dropColumn('total_amount'));
    }
};
