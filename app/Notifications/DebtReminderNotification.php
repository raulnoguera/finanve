<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DebtReminderNotification extends Notification
{
    /** @param \Illuminate\Support\Collection<int, \App\Models\Debt> $debts */
    public function __construct(private $debts, private string $currency) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Deudas y préstamos por vencer')
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line('Estos pagos vencen pronto:');

        foreach ($this->debts as $d) {
            $when = $d->due_date->isToday() ? '**hoy**' : 'el '.$d->due_date->translatedFormat('d \d\e F');
            $what = $d->kind === 'owe' ? "Debes pagar a **{$d->person}**" : "**{$d->person}** te debe";
            $mail->line("• {$what} **".Money::format($d->remaining(), $this->currency)."** — vence {$when}.");
        }

        return $mail->action('Ver deudas', url('/deudas'))
            ->line('Puedes desactivar estos avisos desde la sección Familia de la app.');
    }
}
