<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $t) {
            // Si el gasto se paga repartido, `amount` es solo TU parte y aquí queda el total de la factura.
            $t->decimal('total_amount', 14, 2)->nullable()->after('amount');
        });

        Schema::table('fixed_expenses', function (Blueprint $t) {
            // split = cada quien paga su parte | reimburse = yo pago el total y me reembolsan
            $t->string('share_mode', 10)->default('split')->after('share_percent');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_expenses', fn (Blueprint $t) => $t->dropColumn('share_mode'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn('total_amount'));
    }
};
