<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SharedDueReminderNotification extends Notification
{
    /** @param \Illuminate\Support\Collection<int, \App\Models\Debt> $debts  (con linkedPerson.household y transaction cargados) */
    public function __construct(private $debts) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $late = $this->debts->contains(fn ($d) => $d->due_date->isPast() && ! $d->due_date->isToday());

        $mail = (new MailMessage)
            ->subject($late ? 'Tienes pagos compartidos vencidos' : 'Tienes pagos compartidos por vencer')
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line($late ? 'Estos gastos compartidos ya pasaron su fecha de pago:' : 'Estos gastos compartidos vencen pronto:');

        foreach ($this->debts as $d) {
            $cur = $d->linkedPerson->household->currency();
            $what = $d->transaction?->note ?: preg_replace('/^Gasto compartido: /', '', (string) $d->note);
            $when = $d->due_date->isToday() ? '**hoy**' : ($d->due_date->isPast() ? 'venció el '.$d->due_date->translatedFormat('d \\d\\e F') : 'vence el '.$d->due_date->translatedFormat('d \\d\\e F'));
            $mail->line("• **{$what}** — te faltan **".Money::format($d->remaining(), $cur)."** ({$when}).");
        }

        return $mail->action('Ver mis pagos', url('/compartidos'))
            ->line('Cuando pagues, repórtalo con "Ya pagué" para que quien compartió el gasto lo vea.')
            ->line('Puedes desactivar estos avisos desde la sección Familia de la app.');
    }
}
