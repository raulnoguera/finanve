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
            $t->softDeletes();
            // Desde cuándo ya no aplica (pausado o eliminado): los meses anteriores conservan su historial
            $t->date('ended_at')->nullable()->after('active');
        });

        // Los ya pausados terminan en la última fecha en que se tocaron
        DB::table('fixed_expenses')->where('active', false)->whereNull('ended_at')->get()->each(function ($f) {
            DB::table('fixed_expenses')->where('id', $f->id)->update(['ended_at' => substr((string) $f->updated_at, 0, 10) ?: now()->toDateString()]);
        });
    }

    public function down(): void
    {
        Schema::table('fixed_expenses', function (Blueprint $t) {
            $t->dropColumn('ended_at');
            $t->dropSoftDeletes();
        });
    }
};
