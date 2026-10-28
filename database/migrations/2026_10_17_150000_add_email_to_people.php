<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $t) {
            // Correo de la persona (opcional): sirve para invitarla a ver lo que se le comparte.
            $t->string('email', 120)->nullable()->after('relation');
        });

        // Personas que ya tenían una invitación con correo: lo conservamos en su ficha
        DB::table('invitations')->whereNotNull('person_id')->whereNotNull('email')->orderBy('id')->each(function ($i) {
            DB::table('people')->where('id', $i->person_id)->whereNull('email')->update(['email' => $i->email]);
        });
    }

    public function down(): void
    {
        Schema::table('people', fn (Blueprint $t) => $t->dropColumn('email'));
    }
};
