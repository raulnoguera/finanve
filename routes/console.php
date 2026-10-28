<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Latido: permite comprobar que el cron del hosting está corriendo (php artisan cron:status).
Schedule::call(fn () => Cache::forever('scheduler:last-run', now()->toDateTimeString()))->everyMinute()->name('heartbeat');
Schedule::command('rates:refresh')->hourly();
Schedule::command('fixed:sync-shares')->dailyAt('06:00');
Schedule::command('notify:debts')->dailyAt('08:00');
Schedule::command('notify:fixed')->monthlyOn(1, '09:00');
Schedule::command('notify:monthly')->monthlyOn(1, '08:30');
