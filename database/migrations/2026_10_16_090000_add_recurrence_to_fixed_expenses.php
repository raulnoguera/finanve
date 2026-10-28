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
            // Periodicidad: cada N meses o cada N días (mensual = month/1 sin fecha de inicio, como antes).
            $t->string('interval_unit', 5)->default('month')->after('due_day');   // month | day
            $t->unsignedSmallInteger('interval_count')->default(1)->after('interval_unit');
            $t->date('start_date')->nullable()->after('interval_count');          // primer vencimiento
            $t->unsignedSmallInteger('installments')->nullable()->after('start_date'); // nº de cuotas (opcional)
            // Compartir automáticamente cada pago con una persona (p. ej. la universidad de mi hermana)
            $t->foreignId('share_person_id')->nullable()->after('person_id')->constrained('people')->nullOnDelete();
            $t->decimal('share_percent', 5, 2)->nullable()->after('share_person_id');
        });

        Schema::table('transactions', function (Blueprint $t) {
            // Fecha de vencimiento de la cuota que se pagó (identifica cada ocurrencia)
            $t->date('fixed_due')->nullable()->after('fixed_month')->index();
        });

        // Pagos de gastos fijos ya registrados: su vencimiento era el día configurado de ese mes.
        DB::table('transactions')->whereNotNull('fixed_month')->whereNotNull('fixed_expense_id')->orderBy('id')->each(function ($tx) {
            $f = DB::table('fixed_expenses')->find($tx->fixed_expense_id);
            if (! $f) {
                return;
            }
            $m = \Carbon\Carbon::createFromFormat('Y-m-d', $tx->fixed_month.'-01');
            DB::table('transactions')->where('id', $tx->id)
                ->update(['fixed_due' => $m->day(min($f->due_day, $m->daysInMonth))->toDateString()]);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn('fixed_due'));
        Schema::table('fixed_expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('share_person_id');
            $t->dropColumn(['interval_unit', 'interval_count', 'start_date', 'installments', 'share_percent']);
        });
    }
};
