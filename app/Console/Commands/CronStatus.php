<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CronStatus extends Command
{
    protected $signature = 'cron:status';
    protected $description = 'Indica si el cron del servidor está ejecutando el programador';

    public function handle(): int
    {
        $last = Cache::get('scheduler:last-run');
        if (! $last) {
            $this->warn('Aún no se registra ninguna ejecución del cron.');

            return self::FAILURE;
        }

        $ago = now()->diffInSeconds(\Carbon\Carbon::parse($last), true);
        $this->line("Última ejecución: {$last} (hace ".round($ago).' s)');
        $ok = $ago < 180;
        $ok ? $this->info('Cron OK') : $this->error('El cron no corre desde hace más de 3 minutos.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
