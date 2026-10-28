<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordEs extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Restablece tu contraseña de Finanve')
            ->greeting('Hola, '.explode(' ', trim($notifiable->name))[0])
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Crear nueva contraseña', $this->resetUrl($notifiable))
            ->line("Este enlace vence en {$minutes} minutos.")
            ->line('Si tú no lo pediste, no hace falta hacer nada: tu contraseña sigue igual.')
            ->salutation('Equipo Finanve');
    }
}
