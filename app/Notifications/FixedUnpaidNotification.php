<?php

namespace App\Notifications;

use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FixedUnpaidNotification extends Notification
{
    /** @param array<int, array{name:string, amount:float, due:Carbon}> $rows */
    public function __construct(private Carbon $month, private array $rows, private string $currency) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $name = $this->month->translatedFormat('F \d\e Y');
        $total = collect($this->rows)->sum('amount');
        $n = count($this->rows);

        $mail = (new MailMessage)
            ->subject($n === 1 ? "Te quedó un gasto fijo sin pagar de {$name}" : "Te quedaron {$n} gastos fijos sin pagar de {$name}")
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line("Terminó **{$name}** y estos gastos fijos no aparecen como pagados:");

        foreach ($this->rows as $r) {
            $mail->line('• **'.$r['name'].'** — '.Money::format($r['amount'], $this->currency).' (vencía el '.$r['due']->translatedFormat('d \d\e F').')');
        }

        return $mail
            ->line('**Total pendiente: '.Money::format($total, $this->currency).'**')
            ->line('Si ya los pagaste, márcalos como pagados para que tus reportes queden al día.')
            ->action('Revisar gastos fijos', url('/gastos-fijos?mes='.$this->month->format('Y-m')))
            ->line('Puedes desactivar estos avisos desde la sección Familia de la app.');
    }
}
