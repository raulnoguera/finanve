<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $t) {
            // paid = ya pagado (cuenta en saldos, presupuestos y reportes); pending = registrado, por confirmar el pago
            $t->string('status', 10)->default('paid')->index()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn('status'));
    }
};
