<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function loginForm()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $cred = $r->validate(['email' => ['required', 'email'], 'password' => ['required']]);

        if (! Auth::attempt($cred, $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'Correo o contraseña incorrectos.'])->onlyInput('email');
        }

        $r->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }

    public function registerForm(Request $r, ?string $token = null)
    {
        $invitation = $token ? Invitation::with('household', 'inviter')->where('token', $token)->first() : null;

        if ($token && (! $invitation || ! $invitation->isUsable())) {
            return redirect()->route('register')->withErrors(['token' => 'Esta invitación no es válida o ya venció. Pide una nueva.']);
        }

        // Sin invitación, primero se elige el país (paso 1).
        $country = strtoupper((string) $r->query('pais'));
        if (! $invitation && ! config("countries.$country")) {
            return view('auth.country', ['countries' => config('countries')]);
        }

        return view('auth.register', compact('invitation', 'country'));
    }

    public function register(Request $r)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'token' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('countries')))],
        ]);

        $token = $data['token'] ?? null;
        $invitation = $token ? Invitation::where('token', $token)->first() : null;

        if ($token && (! $invitation || ! $invitation->isUsable())) {
            return back()->withErrors(['token' => 'La invitación ya no es válida.'])->withInput();
        }

        if (! $invitation && empty($data['country'])) {
            return redirect()->route('register');
        }

        $user = DB::transaction(function () use ($data, $invitation) {
            if ($invitation && ! $invitation->isParticipant()) {
                $household = $invitation->household;
                $role = 'member';
            } else {
                // Cuenta propia: también para quien llega como participante (solo verá lo que le compartan).
                $household = Household::create([
                    'name' => 'Familia de '.explode(' ', trim($data['name']))[0],
                    'country' => $invitation?->household?->country ?? $data['country'],
                ]);
                $household->seedCategories();
                $role = 'owner';
            }

            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'],
                'password' => $data['password'], 'household_id' => $household->id, 'role' => $role,
            ]);

            $household->mePerson($user);

            if ($invitation?->isParticipant()) {
                \App\Models\Person::whereKey($invitation->person_id)->whereNull('user_id')->update(['user_id' => $user->id]);
            }

            $invitation?->update(['accepted_at' => now()]);

            return $user;
        });

        app(\App\Services\Notifier::class)->safely(fn () => $user->notify(new \App\Notifications\WelcomeNotification));

        Auth::login($user);
        $r->session()->regenerate();

        return redirect()->route($invitation?->isParticipant() ? 'shared.index' : 'dashboard');
    }

    /** Una persona que ya tiene cuenta acepta ver los gastos que le comparten (por el enlace del correo). */
    public function acceptShare(Request $r, string $token)
    {
        $inv = Invitation::with('person')->where('token', $token)->whereNotNull('person_id')->first();

        if (! $inv) {
            return redirect()->route('dashboard')->with('error', 'Esta invitación no es válida o ya venció. Pide una nueva.');
        }

        $error = $inv->acceptFor($r->user());

        return $error
            ? redirect()->route('dashboard')->with('error', $error)
            : redirect()->route('shared.index')->with('ok', 'Listo: ahora verás aquí los gastos que te compartan.');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
