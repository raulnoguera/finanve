<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('household_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 60);
            $t->decimal('amount', 14, 2);          // monto habitual, en la moneda base de la familia
            $t->unsignedTinyInteger('due_day')->default(1);   // día del mes en que vence (1-31)
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::table('transactions', function (Blueprint $t) {
            // Un gasto pagado desde "Gastos fijos": a qué fijo y de qué mes (Y-m) corresponde.
            $t->foreignId('fixed_expense_id')->nullable()->after('category_id')->constrained('fixed_expenses')->nullOnDelete();
            $t->string('fixed_month', 7)->nullable()->after('fixed_expense_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fixed_expense_id');
            $t->dropColumn('fixed_month');
        });
        Schema::dropIfExists('fixed_expenses');
    }
};
