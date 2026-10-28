<?php

namespace App\Providers;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rastro de correos (nivel warning para que se vea con LOG_LEVEL=warning): qué se intentó y qué aceptó el SMTP.
        $summary = fn ($m) => [
            'to' => collect($m->getTo())->map->getAddress()->join(','),
            'subject' => $m->getSubject(),
            'sapi' => PHP_SAPI,
        ];
        Event::listen(MessageSending::class, fn ($e) => Log::warning('mail.sending', $summary($e->message)));
        Event::listen(MessageSent::class, fn ($e) => Log::warning('mail.sent', $summary($e->message)));
    }
}
