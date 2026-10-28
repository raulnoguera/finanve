<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Notifications\MonthlySummaryNotification;
use App\Services\FinanceService;
use App\Services\Notifier;
use Illuminate\Console\Command;

class NotifyMonthly extends Command
{
    protected $signature = 'notify:monthly {--month= : Mes a resumir (Y-m); por defecto el anterior}';
    protected $description = 'Envía el resumen del mes anterior a cada familia';

    public function handle(Notifier $notifier, FinanceService $fin): int
    {
        $month = $this->option('month')
            ? \Carbon\Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $sent = 0;

        Household::query()->each(function (Household $h) use ($notifier, $fin, $month, &$sent) {
            $summary = $fin->month($h, $month);
            if ($summary['income'] == 0 && $summary['expense'] == 0) {
                return;
            }
            if (! $notifier->once("monthly:{$h->id}:{$month->format('Y-m')}", 60)) {
                return;
            }

            $surplus = $fin->averageMonthlySurplus($h);
            $goals = $h->goals()->whereNull('completed_at')->orderBy('deadline')->take(3)->get()
                ->map(fn ($g) => ['name' => $g->name] + $fin->goalStatus($g, $surplus))->all();

            $notifier->household($h, new MonthlySummaryNotification($month, $summary, $goals, $h->currency()));
            $sent++;
        });

        $this->info("Resúmenes enviados: {$sent}");

        return self::SUCCESS;
    }
}
