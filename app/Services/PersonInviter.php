<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/** Invita a una persona de la lista a ver los gastos que se le compartan. */
class PersonInviter
{
    /**
     * Crea la invitación y avisa. Devuelve [tipo flash ('ok'|'error'), mensaje, enlace|null].
     * Si el correo ya tiene cuenta, le aparece una solicitud dentro de la app.
     */
    public function issue(User $by, Person $person, ?string $email): array
    {
        if ($person->user_id) {
            return ['error', "{$person->name} ya tiene una cuenta vinculada.", null];
        }

        $existing = $email ? User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first() : null;

        if ($existing && $existing->household_id === $person->household_id) {
            return ['error', "{$existing->name} ya forma parte de tu familia: ve todo directamente, no necesita invitación.", null];
        }

        // Una sola invitación vigente por persona
        $person->household->invitations()->where('person_id', $person->id)->whereNull('accepted_at')->delete();
        $inv = Invitation::issueForPerson($person, $by, $email);

        $mailed = false;
        if ($email) {
            app(Notifier::class)->safely(function () use ($inv, $email, &$mailed) {
                Notification::route('mail', $email)->notify(new \App\Notifications\ParticipantInvitationNotification($inv));
                $mailed = true;
            });
        }

        $msg = match (true) {
            (bool) $existing => "{$email} ya tiene cuenta en Finanve: le aparecerá una solicitud dentro de la app para aceptarla con un toque".($mailed ? ' (y le enviamos un correo).' : '.'),
            $mailed => "Invitación enviada a {$email}. También puedes compartir el enlace.",
            default => "Enlace creado. Compártelo con {$person->name} para que cree su cuenta.",
        };

        return ['ok', $msg, route('register', $inv->token)];
    }
}
