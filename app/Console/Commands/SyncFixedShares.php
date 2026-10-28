<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\FixedShareService;
use Illuminate\Console\Command;

class SyncFixedShares extends Command
{
    protected $signature = 'fixed:sync-shares';
    protected $description = 'Crea con anticipación las partes compartidas de las próximas cuotas de gastos fijos';

    public function handle(FixedShareService $svc): int
    {
        $n = 0;
        Household::query()->each(function (Household $h) use ($svc, &$n) {
            $n += $svc->sync($h);
        });

        $this->info("Partes programadas creadas: {$n}");

        return self::SUCCESS;
    }
}
