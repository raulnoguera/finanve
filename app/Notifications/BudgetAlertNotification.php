<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetAlertNotification extends Notification
{
    /** @param 'near'|'over' $level */
    public function __construct(
        private string $category, private float $spent, private float $budget,
        private string $level, private string $currency,
    ) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $m = fn ($n) => Money::format($n, $this->currency);
        $over = $this->level === 'over';

        return (new MailMessage)
            ->subject($over ? "Te pasaste del presupuesto de {$this->category}" : "Vas por el 80% del presupuesto de {$this->category}")
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line($over
                ? "Este mes llevan gastado **{$m($this->spent)}** en **{$this->category}** y el límite era **{$m($this->budget)}** (te pasaste por {$m($this->spent - $this->budget)})."
                : "Este mes llevan gastado **{$m($this->spent)}** de **{$m($this->budget)}** en **{$this->category}**. Te quedan {$m($this->budget - $this->spent)}.")
            ->action('Ver presupuesto', url('/presupuesto'))
            ->line($this->footer());
    }

    private function footer(): string
    {
        return 'Puedes desactivar estos avisos desde la sección Familia de la app.';
    }
}
