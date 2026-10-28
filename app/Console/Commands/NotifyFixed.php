<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Notifications\FixedUnpaidNotification;
use App\Services\FixedExpenseService;
use App\Services\Notifier;
use Illuminate\Console\Command;

class NotifyFixed extends Command
{
    protected $signature = 'notify:fixed {--month= : Mes a revisar (Y-m); por defecto el anterior}';
    protected $description = 'Avisa de gastos fijos del mes anterior que quedaron sin pagar';

    public function handle(Notifier $notifier, FixedExpenseService $svc): int
    {
        $month = $this->option('month')
            ? \Carbon\Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $sent = 0;

        Household::query()->each(function (Household $h) use ($notifier, $svc, $month, &$sent) {
            $unpaid = $svc->month($h, $month)->where('status', '!=', 'paid');
            if ($unpaid->isEmpty() || ! $notifier->once("fixed:{$h->id}:{$month->format('Y-m')}", 60)) {
                return;
            }

            $rows = $unpaid->map(fn ($r) => ['name' => $r['fixed']->name, 'amount' => (float) $r['fixed']->amount, 'due' => $r['due']])->values()->all();
            $notifier->household($h, new FixedUnpaidNotification($month, $rows, $h->currency()));
            $sent++;
        });

        $this->info("Familias avisadas: {$sent}");

        return self::SUCCESS;
    }
}
