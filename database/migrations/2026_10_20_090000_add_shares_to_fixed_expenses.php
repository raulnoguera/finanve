<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_expenses', function (Blueprint $t) {
            // Lista de participantes: [{person_id, percent}, ...]. share_person_id/share_percent
            // se conservan como "primer participante" y "total de los demás".
            $t->json('shares')->nullable()->after('share_mode');
        });

        DB::table('fixed_expenses')->whereNotNull('share_person_id')->whereNotNull('share_percent')->get()->each(function ($f) {
            DB::table('fixed_expenses')->where('id', $f->id)->update([
                'shares' => json_encode([['person_id' => (int) $f->share_person_id, 'percent' => (float) $f->share_percent]]),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('fixed_expenses', fn (Blueprint $t) => $t->dropColumn('shares'));
    }
};
