<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Avisos opcionales por correo (alertas, recordatorios, resumen). Los correos de cuenta siempre se envían.
            $t->boolean('notify_email')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notify_email'));
    }
};
