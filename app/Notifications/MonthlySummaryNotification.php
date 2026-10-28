<?php

namespace App\Notifications;

use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlySummaryNotification extends Notification
{
    public function __construct(private Carbon $month, private array $summary, private array $goals, private string $currency) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $m = fn ($n, $s = false) => Money::format($n, $this->currency, $s);
        $s = $this->summary;
        $name = $this->month->translatedFormat('F \d\e Y');

        $mail = (new MailMessage)
            ->subject("Tu resumen de {$name}")
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line("Así cerró **{$name}**:")
            ->line("• Ingresos: **{$m($s['income'])}**")
            ->line("• Gastos: **{$m($s['expense'])}**")
            ->line('• Balance: **'.$m($s['balance'], true).'**'.($s['savings_rate'] !== null ? " (ahorraste el {$s['savings_rate']}%)" : ''));

        $top = collect($s['by_category'])->take(3);
        if ($top->isNotEmpty()) {
            $mail->line('**Donde más se fue el dinero:** '.$top->map(fn ($c) => "{$c->name} ({$m($c->total)})")->join(', ').'.');
        }

        foreach ($this->goals as $g) {
            $mail->line("🎯 **{$g['name']}**: {$g['pct']}% — te faltan {$m($g['remaining'])}".($g['monthsToGo'] ? ", al ritmo actual en {$g['monthsToGo']} ".($g['monthsToGo'] == 1 ? 'mes' : 'meses') : '').'.');
        }

        return $mail->action('Ver el detalle', url('/?mes='.$this->month->format('Y-m')))
            ->line('Puedes desactivar este resumen desde la sección Familia de la app.');
    }
}
