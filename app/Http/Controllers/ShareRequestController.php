<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use Illuminate\Http\Request;

class ShareRequestController extends Controller
{
    private function mine(Request $r, int $id): Invitation
    {
        return Invitation::pendingFor($r->user())->with('person', 'inviter')->findOrFail($id);
    }

    public function accept(Request $r, int $invitation)
    {
        $inv = $this->mine($r, $invitation);
        $error = $inv->acceptFor($r->user());

        return $error
            ? back()->with('error', $error)
            : redirect()->route('shared.index')->with('ok', "Listo: ahora verás los gastos que {$inv->inviter->name} comparta contigo.");
    }

    public function decline(Request $r, int $invitation)
    {
        $inv = $this->mine($r, $invitation);
        $inv->update(['declined_at' => now()]);

        return back()->with('ok', 'Solicitud rechazada. No verás gastos de '.$inv->inviter->name.'.');
    }
}
