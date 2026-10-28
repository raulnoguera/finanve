<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_expenses', function (Blueprint $t) {
            // Periodo (p. ej. 4 meses) dentro del cual se pagan las cuotas (p. ej. cada 21 días); opcionalmente se repite.
            $t->unsignedSmallInteger('duration_months')->nullable()->after('installments');
            $t->unsignedSmallInteger('repeat_months')->nullable()->after('duration_months');
        });

        Schema::table('debts', function (Blueprint $t) {
            // Parte programada de una cuota de gasto fijo (existe aunque el titular aún no haya pagado esa cuota)
            $t->foreignId('fixed_expense_id')->nullable()->after('transaction_id')->constrained('fixed_expenses')->nullOnDelete();
            $t->date('fixed_due')->nullable()->after('fixed_expense_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fixed_expense_id');
            $t->dropColumn('fixed_due');
        });
        Schema::table('fixed_expenses', fn (Blueprint $t) => $t->dropColumn(['duration_months', 'repeat_months']));
    }
};
