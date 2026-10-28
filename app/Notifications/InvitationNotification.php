<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(private Invitation $invitation) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $i = $this->invitation->loadMissing('household', 'inviter');

        return (new MailMessage)
            ->subject($i->inviter->name.' te invitó a Finanve')
            ->greeting('Hola 👋')
            ->line("**{$i->inviter->name}** te invitó a unirte a **{$i->household->name}** en Finanve para llevar juntos los gastos, metas y deudas de la familia.")
            ->action('Crear mi cuenta', route('register', $i->token))
            ->line('El enlace es personal y vence el '.$i->expires_at->translatedFormat('d \d\e F').'. Si no esperabas esta invitación, ignora este correo.')
            ->salutation('Equipo Finanve');
    }
}
