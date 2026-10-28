<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    public function index(Request $r)
    {
        $h = $this->household($r);

        return view('family.index', [
            'household' => $h,
            'members' => $h->users()->orderBy('role')->orderBy('name')->get(),
            'people' => $h->people()->with('account')->withSum(['transactions as month_total' => fn ($q) => $q->where('type', 'expense')
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])], 'amount')->orderBy('name')->get(),
            'invitations' => $h->invitations()->whereNull('person_id')->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get(),
            'personInvites' => $h->invitations()->whereNotNull('person_id')->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get()->unique('person_id')->keyBy('person_id'),
            'existingEmails' => \App\Models\User::whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(email)'),
                $h->invitations()->whereNotNull('person_id')->whereNull('accepted_at')->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e)))->pluck('email')->map(fn ($e) => mb_strtolower($e))->all(),
        ]);
    }

    public function invite(Request $r)
    {
        abort_unless($r->user()->isOwner(), 403);
        $data = $r->validate(['email' => ['nullable', 'email', 'max:120']]);

        $inv = Invitation::issue($this->household($r), $r->user(), $data['email'] ?? null);

        $mailed = false;
        if (! empty($data['email'])) {
            app(\App\Services\Notifier::class)->safely(function () use ($inv, $data, &$mailed) {
                \Illuminate\Support\Facades\Notification::route('mail', $data['email'])->notify(new \App\Notifications\InvitationNotification($inv));
                $mailed = true;
            });
        }

        return back()->with('ok', $mailed
                ? "Invitación enviada a {$data['email']}. También puedes compartir el enlace."
                : 'Invitación creada. Comparte el enlace con tu familiar.')
            ->with('link', route('register', $inv->token));
    }

    public function revoke(Request $r, Invitation $invitation)
    {
        abort_unless($r->user()->isOwner(), 403);
        $this->own($r, $invitation)->delete();

        return back()->with('ok', 'Invitación cancelada.');
    }

    public function remove(Request $r, User $user)
    {
        abort_unless($r->user()->isOwner() && $user->household_id === $r->user()->household_id && $user->id !== $r->user()->id, 403);
        // El miembro removido conserva su cuenta con una familia nueva y vacía.
        $own = Household::create(['name' => 'Familia de '.explode(' ', trim($user->name))[0]]);
        $own->seedCategories();
        $user->update(['household_id' => $own->id, 'role' => 'owner']);

        return back()->with('ok', 'Miembro removido.');
    }

    public function rename(Request $r)
    {
        abort_unless($r->user()->isOwner(), 403);
        $data = $r->validate(['name' => ['required', 'string', 'max:60']]);
        $this->household($r)->update($data);

        return back()->with('ok', 'Nombre actualizado.');
    }
}
