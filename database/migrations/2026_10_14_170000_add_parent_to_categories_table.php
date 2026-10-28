<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            // Subcategorías (un solo nivel): Comida › Restaurantes
            $t->foreignId('parent_id')->nullable()->after('household_id')->constrained('categories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
        });
    }
};
