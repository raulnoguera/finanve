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
            // La persona "yo": representa a un miembro (usuario) de la familia, para poder elegir "Para mí".
            $t->foreignId('member_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        // Cada usuario existente obtiene su persona "yo".
        $palette = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#0ea5e9', '#8b5cf6', '#f97316', '#14b8a6'];
        DB::table('users')->whereNotNull('household_id')->orderBy('id')->each(function ($u) use ($palette) {
            DB::table('people')->insert([
                'household_id' => $u->household_id, 'member_user_id' => $u->id, 'name' => $u->name, 'relation' => 'yo',
                'color' => $palette[crc32(mb_strtolower($u->name)) % count($palette)],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('people')->whereNotNull('member_user_id')->delete();
        Schema::table('people', fn (Blueprint $t) => $t->dropConstrainedForeignId('member_user_id'));
    }
};
