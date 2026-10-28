<?php

namespace App\Http\Controllers;

use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketController extends Controller
{
    public function index(Request $r)
    {
        $h = $this->household($r);
        $cats = $h->categories()->where('type', 'expense')->orderBy('name')->get();

        return view('market.index', [
            'cats' => $cats,
            'kids' => $cats->whereNotNull('parent_id')->groupBy('parent_id'),
            'defaultCategory' => optional($cats->whereNull('parent_id')->firstWhere('name', 'Mercado'))->id,
            'currency' => config('currencies.'.base_currency()),
            'places' => $h->transactions()->whereNotNull('place')->latest('id')->pluck('place')->unique()->take(20)->values(),
        ]);
    }

    /** Convierte la lista en un gasto, guardando el detalle de productos. */
    public function store(Request $r, Notifier $notifier)
    {
        $h = $this->household($r);

        $data = $r->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'currency' => ['nullable', Rule::in(currency_codes())],
            'place' => ['nullable', 'string', 'max:60'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('household_id', $h->id)->where('type', 'expense')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:140'],
            'items_json' => ['nullable', 'string', 'max:60000'],
        ]);

        $items = collect(json_decode($data['items_json'] ?? '[]', true) ?: [])
            ->filter(fn ($i) => is_array($i) && isset($i['price']) && is_numeric($i['price']))
            ->take(200)
            ->map(fn ($i) => [
                'name' => mb_substr(trim((string) ($i['name'] ?? '')), 0, 60),
                'qty' => max(1, min(999, (int) ($i['qty'] ?? 1))),
                'price' => round(max(0, (float) $i['price']), 2),
            ])->values()->all();

        $currency = $data['currency'] ?? base_currency();
        [$amount, $rate] = $this->toUsd((float) $data['amount'], $currency);
        $place = $this->canonicalPlace($r, $data['place'] ?? null);
        $n = count($items);

        $note = ($data['note'] ?? null)
            ?: ($place ? "Compra en {$place}" : 'Mercado').($n ? ' · '.$n.($n === 1 ? ' producto' : ' productos') : '');

        $tx = $h->transactions()->create([
            'user_id' => $r->user()->id,
            'category_id' => $data['category_id'] ?? null,
            'type' => 'expense',
            'amount' => $amount,
            'currency' => $currency,
            'original_amount' => $data['amount'],
            'rate' => $rate,
            'date' => $data['date'],
            'note' => $note,
            'place' => $place,
            'items' => $items ?: null,
        ]);

        $notifier->budgetCheck($h, $tx->category_id, $tx->date);

        return redirect()->route('market.index')
            ->with('ok', 'Compra guardada como gasto: '.money($tx->amount))
            ->with('market_saved', true);
    }
}
