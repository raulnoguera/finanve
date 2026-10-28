<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una persona de la lista puede tener su propia cuenta: solo verá los gastos que se le compartan.
        Schema::table('people', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
        });

        // Invitación "participante": vincula una cuenta propia con la persona (sin unirla a la familia).
        Schema::table('invitations', function (Blueprint $t) {
            $t->foreignId('person_id')->nullable()->after('household_id')->constrained('people')->cascadeOnDelete();
        });

        // Pagos reportados por quien debe su parte.
        Schema::table('debt_payments', function (Blueprint $t) {
            $t->foreignId('reported_by')->nullable()->after('debt_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('reported_by'));
        Schema::table('invitations', fn (Blueprint $t) => $t->dropConstrainedForeignId('person_id'));
        Schema::table('people', fn (Blueprint $t) => $t->dropConstrainedForeignId('user_id'));
    }
};
