<?php

namespace App\Http\Controllers;

use App\Services\RateService;
use Illuminate\Http\Request;

class RateController extends Controller
{
    public function refresh(RateService $rates)
    {
        $ok = $rates->refresh('oficial');
        $rates->refresh('paralelo');

        return back()->with($ok ? 'ok' : 'error', $ok ? 'Tasa actualizada.' : 'No se pudo consultar la tasa ahora. Se usa la última guardada.');
    }

    public function manual(Request $r, RateService $rates)
    {
        $data = $r->validate(['rate' => ['required', 'numeric', 'gt:0', 'max:100000000']]);
        $rates->setManual((float) $data['rate']);

        return back()->with('ok', 'Tasa manual guardada.');
    }
}
