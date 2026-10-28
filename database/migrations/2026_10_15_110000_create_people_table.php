<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personas de la familia que reciben gastos (hijos, pareja, mascota…). No tienen cuenta en la app.
        Schema::create('people', function (Blueprint $t) {
            $t->id();
            $t->foreignId('household_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('relation', 20)->nullable();   // hijo | hermano | pareja | padre | mascota | otro
            $t->string('color', 9)->default('#6366f1');
            $t->timestamps();
        });

        Schema::table('transactions', function (Blueprint $t) {
            $t->foreignId('person_id')->nullable()->after('category_id')->constrained('people')->nullOnDelete();
        });
        Schema::table('fixed_expenses', function (Blueprint $t) {
            $t->foreignId('person_id')->nullable()->after('category_id')->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_expenses', fn (Blueprint $t) => $t->dropConstrainedForeignId('person_id'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('person_id'));
        Schema::dropIfExists('people');
    }
};
