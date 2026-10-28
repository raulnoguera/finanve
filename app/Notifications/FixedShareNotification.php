<?php

namespace App\Notifications;

use App\Models\FixedExpense;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FixedShareNotification extends Notification
{
    public function __construct(private FixedExpense $fixed, private string $owner, private string $currency, private ?string $first, private ?float $percent = null) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $f = $this->fixed;
        $percent = (float) ($this->percent ?? $f->share_percent);
        $pct = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $mine = round((float) $f->amount * $percent / 100, 2);

        $mail = (new MailMessage)
            ->subject("{$this->owner} programó un gasto compartido contigo")
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line("**{$this->owner}** programó **{$f->name}** y te asignó el **{$pct} %**.")
            ->line('Tu parte: **'.Money::format($mine, $this->currency).'** · '.mb_strtolower($f->frequencyLabel()).'.');

        if ($this->first) {
            $mail->line('Primera cuota: '.\Illuminate\Support\Carbon::parse($this->first)->translatedFormat('d \\d\\e F \\d\\e Y').'.');
        }

        return $mail->line('Te iremos recordando cada vencimiento.')
            ->action('Ver mis gastos compartidos', url('/compartidos'));
    }
}
