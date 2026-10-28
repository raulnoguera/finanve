<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonController extends Controller
{
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'relation' => ['nullable', Rule::in(array_keys(Person::selectable()))],
            'email' => ['nullable', 'email', 'max:120'],
            'send_invite' => ['nullable', 'boolean'],
        ];
    }

    private function cleanEmail(?string $e): ?string
    {
        $e = trim((string) $e);

        return $e === '' ? null : mb_strtolower($e);
    }

    public function store(Request $r)
    {
        $h = $this->household($r);
        $data = $r->validate($this->rules());
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $email = $this->cleanEmail($data['email'] ?? null);

        if ($h->people()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return back()->withErrors(['name' => "Ya tienes a «{$name}» en la lista."])->withInput();
        }

        $person = $h->people()->create([
            'name' => $name, 'relation' => $data['relation'] ?? null, 'email' => $email, 'color' => Person::colorFor($name),
        ]);

        // Con correo y "invitarle ahora" marcado: se le invita de una vez (o le llega la solicitud si ya tiene cuenta)
        if ($email && $r->boolean('send_invite', true)) {
            [$type, $msg, $link] = app(\App\Services\PersonInviter::class)->issue($r->user(), $person, $email);

            return back()->with($type, $type === 'ok' ? "{$name} agregado/a. ".$msg : "{$name} agregado/a, pero: ".$msg)
                ->with('link', $link);
        }

        return back()->with('ok', "{$name} agregado/a. Ya puedes asignarle gastos.");
    }

    public function update(Request $r, Person $person)
    {
        $this->own($r, $person);
        $data = $r->validate($this->rules());
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));

        $dup = $this->household($r)->people()->where('id', '!=', $person->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($dup) {
            return back()->withErrors(['name' => "Ya tienes a «{$name}» en la lista."]);
        }

        $person->update(['name' => $name, 'relation' => $data['relation'] ?? null, 'email' => $this->cleanEmail($data['email'] ?? null)]);

        return back()->with('ok', 'Persona actualizada.');
    }

    public function destroy(Request $r, Person $person)
    {
        $this->own($r, $person)->delete();

        return back()->with('ok', 'Persona eliminada. Sus gastos se conservan, sin persona asignada.');
    }

    /** Invita a la persona a crear su cuenta (solo verá los gastos que se le compartan). */
    public function invite(Request $r, Person $person)
    {
        $this->own($r, $person);
        $data = $r->validate(['email' => ['nullable', 'email', 'max:120']]);
        $email = $this->cleanEmail($data['email'] ?? null) ?? $person->email;

        // Guarda el correo en la ficha para no volver a pedirlo
        if ($email && ! $person->email) {
            $person->update(['email' => $email]);
        }

        [$type, $msg, $link] = app(\App\Services\PersonInviter::class)->issue($r->user(), $person, $email);

        return back()->with($type, $msg)->with('link', $link);
    }

    /** Quita el vínculo: la persona deja de ver los gastos compartidos (su cuenta sigue existiendo). */
    public function unlink(Request $r, Person $person)
    {
        $this->own($r, $person)->update(['user_id' => null]);

        return back()->with('ok', "{$person->name} ya no puede ver tus gastos compartidos.");
    }
}
