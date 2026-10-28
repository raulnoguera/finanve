<?php

namespace App\Console\Commands;

use App\Services\RateService;
use Illuminate\Console\Command;

class RefreshRates extends Command
{
    protected $signature = 'rates:refresh';
    protected $description = 'Actualiza la tasa USD/Bs (oficial y paralelo)';

    public function handle(RateService $rates): int
    {
        foreach (['oficial', 'paralelo'] as $source) {
            $this->line($source.': '.($rates->refresh($source) ? 'ok' : 'falló'));
        }
        return self::SUCCESS;
    }
}
