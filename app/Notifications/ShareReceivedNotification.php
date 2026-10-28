<?php

namespace App\Notifications;

use App\Models\Debt;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShareReceivedNotification extends Notification
{
    public function __construct(private Debt $debt, private string $currency) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $d = $this->debt->loadMissing('user', 'transaction.category');
        $what = $d->transaction?->note ?: ($d->transaction?->category?->name ?? 'un gasto');
        $pct = rtrim(rtrim(number_format((float) $d->percent, 2, '.', ''), '0'), '.');

        $mail = (new MailMessage)
            ->subject("{$d->user->name} compartió un gasto contigo")
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line("**{$d->user->name}** compartió contigo **{$what}**.")
            ->line("Te corresponde el **{$pct} %**: **".Money::format($d->amount, $this->currency).'**.');

        if ($d->due_date) {
            $mail->line('Fecha para pagar: '.$d->due_date->translatedFormat('d \\d\\e F').'.');
        }

        return $mail->action('Ver mis gastos compartidos', url('/compartidos'))
            ->line('Puedes desactivar estos avisos desde la sección Familia de la app.');
    }
}
