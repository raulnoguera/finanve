<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $t) {
            // Gasto compartido: de qué gasto viene, quién debe su parte y qué % le tocó.
            $t->foreignId('transaction_id')->nullable()->after('user_id')->constrained('transactions')->nullOnDelete();
            $t->foreignId('person_id')->nullable()->after('transaction_id')->constrained('people')->nullOnDelete();
            $t->decimal('percent', 5, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('person_id');
            $t->dropConstrainedForeignId('transaction_id');
            $t->dropColumn('percent');
        });
    }
};
