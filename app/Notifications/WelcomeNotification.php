<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('¡Bienvenido a Finanve!')
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0].' 👋')
            ->line('Tu cuenta está lista. Para sacarle provecho desde hoy:')
            ->line('1. Registra tus primeros ingresos y gastos con el botón **+**.')
            ->line('2. Define un límite mensual por categoría en **Presupuesto**.')
            ->line('3. Crea una **meta** (un viaje, un carro…) y mira qué tan cerca estás.')
            ->line('4. Invita a tu familia desde **Familia** para llevar las cuentas juntos.')
            ->action('Abrir Finanve', url('/'))
            ->salutation('Equipo Finanve');
    }
}
