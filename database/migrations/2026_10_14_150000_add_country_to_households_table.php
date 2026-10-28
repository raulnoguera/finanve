<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $t) {
            // País desde donde se usa la app; las familias existentes quedan en Venezuela.
            $t->string('country', 2)->default('VE')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('households', fn (Blueprint $t) => $t->dropColumn('country'));
    }
};
