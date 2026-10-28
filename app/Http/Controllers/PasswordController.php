<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    public function forgot()
    {
        return view('auth.forgot');
    }

    public function email(Request $r)
    {
        $r->validate(['email' => ['required', 'email']]);

        try {
            Password::sendResetLink($r->only('email'));
        } catch (\Throwable $e) {
            report($e);
        }

        // Misma respuesta exista o no la cuenta (no revelar qué correos están registrados).
        return back()->with('ok', 'Si el correo está registrado, te enviamos un enlace para crear una nueva contraseña. Revisa también la carpeta de spam.');
    }

    public function resetForm(Request $r, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $r->query('email')]);
    }

    public function reset(Request $r)
    {
        $data = $r->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('ok', 'Contraseña actualizada. Ya puedes entrar.')
            : back()->withErrors(['email' => 'El enlace no es válido o ya venció. Pide uno nuevo.'])->withInput($r->only('email'));
    }

    public function preferences(Request $r)
    {
        $r->user()->update(['notify_email' => $r->boolean('notify_email')]);

        return back()->with('ok', $r->user()->notify_email ? 'Avisos por correo activados.' : 'Avisos por correo desactivados.');
    }
}
