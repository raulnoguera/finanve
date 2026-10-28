<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ParticipantInvitationNotification extends Notification
{
    public function __construct(private Invitation $invitation) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $i = $this->invitation->loadMissing('inviter', 'person');

        return (new MailMessage)
            ->subject($i->inviter->name.' quiere compartir gastos contigo en Finanve')
            ->greeting('Hola '.($i->person?->name ? explode(' ', $i->person->name)[0] : ''))
            ->line("**{$i->inviter->name}** te invitó a Finanve para compartir gastos contigo. Tendrás tu **propia cuenta** y solo verás los gastos que te comparta: cuánto te toca, cuánto has pagado y cuánto falta.")
            ->action('Crear mi cuenta', route('register', $i->token))
            ->line('¿Ya tienes cuenta en Finanve? [Entra y acepta aquí]('.route('share.accept', $i->token).').')
            ->line('El enlace es personal y vence el '.$i->expires_at->translatedFormat('d \\d\\e F').'.')
            ->salutation('Equipo Finanve');
    }
}
