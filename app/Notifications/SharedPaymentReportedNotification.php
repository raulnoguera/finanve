<?php

namespace App\Notifications;

use App\Models\DebtPayment;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SharedPaymentReportedNotification extends Notification
{
    public function __construct(private DebtPayment $payment, private string $currency) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $p = $this->payment->loadMissing('debt.transaction', 'reporter');
        $d = $p->debt;
        $who = $p->reporter?->name ?? $d->person;
        $left = $d->remaining();
        $received = $d->kind === 'owe';   // quien tenía que cobrar confirma que recibió el dinero
        $what = $d->transaction?->note ?: ($received ? $d->person : 'un gasto compartido');

        return (new MailMessage)
            ->subject($received ? "{$who} confirmó que recibió un pago" : "{$who} reportó un pago")
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line($received
                ? "**{$who}** confirmó que recibió **".Money::format($p->amount, $this->currency)."** de «{$what}»."
                : "**{$who}** reportó que pagó **".Money::format($p->amount, $this->currency)."** de {$what}.")
            ->line($left > 0.004 ? 'Todavía falta '.Money::format($left, $this->currency).'.' : ($received ? '**La deuda quedó saldada.**' : '**Su parte quedó saldada.**'))
            ->action('Ver en Deudas', url('/deudas'))
            ->line('Si el pago no es correcto, puedes deshacerlo desde Deudas.');
    }
}
