<?php

namespace App\Http\Controllers;

use App\Services\FinanceService;
use App\Services\FixedExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $r, FinanceService $fin, FixedExpenseService $fixedSvc)
    {
        $h = $this->household($r);
        $month = $this->month($r);
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $summary = $fin->month($h, $month);
        $prev = $fin->month($h, $month->copy()->subMonth());

        // Gastos fijos (pagados desde "Gastos fijos") vs imprevistos / variables
        $expenses = $h->transactions()->where('type', 'expense')->whereBetween('date', [$from, $to]);
        $fixedPaid = (float) (clone $expenses)->whereNotNull('fixed_month')->sum('amount');
        $variable = max(0, $summary['expense'] - $fixedPaid);
        $fixedRows = $fixedSvc->month($h, $month);

        // Gasto por categoría con subcategorías
        $direct = $fin->spentByCategory($h, $month);
        $roll = $fin->spentRollup($h, $month);
        $cats = $h->categories()->with('children')->whereNull('parent_id')->where('type', 'expense')->get()
            ->map(fn ($c) => [
                'name' => $c->name, 'color' => $c->color, 'total' => $roll[$c->id] ?? 0,
                'own' => $direct[$c->id] ?? 0, 'budget' => (float) $c->monthly_budget,
                'children' => $c->children->map(fn ($k) => ['name' => $k->name, 'total' => $direct[$k->id] ?? 0])
                    ->filter(fn ($k) => $k['total'] > 0)->sortByDesc('total')->values(),
            ])->filter(fn ($c) => $c['total'] > 0)->sortByDesc('total')->values();
        $uncategorized = (float) (clone $expenses)->whereNull('category_id')->sum('amount');

        // Ingresos por categoría con subcategorías
        $inc = $fin->incomeByCategory($h, $month);
        $incomeCats = $h->categories()->with('children')->whereNull('parent_id')->where('type', 'income')->get()
            ->map(fn ($c) => [
                'name' => $c->name, 'color' => $c->color, 'total' => $inc['rollup'][$c->id] ?? 0, 'own' => $inc['direct'][$c->id] ?? 0,
                'children' => $c->children->map(fn ($k) => ['name' => $k->name, 'total' => $inc['direct'][$k->id] ?? 0])
                    ->filter(fn ($k) => $k['total'] > 0)->sortByDesc('total')->values(),
            ])->filter(fn ($c) => $c['total'] > 0)->sortByDesc('total')->values();
        $incomeUncategorized = (float) $h->transactions()->where('type', 'income')->whereNull('category_id')
            ->whereBetween('date', [$from, $to])->sum('amount');

        // Tendencia de 6 meses
        $trend = collect(range(5, 0))->map(function ($i) use ($fin, $h, $month) {
            $m = $month->copy()->subMonthsNoOverflow($i);
            $s = $fin->month($h, $m);

            return ['label' => ucfirst($m->translatedFormat('M')), 'income' => $s['income'], 'expense' => $s['expense']];
        });

        // ¿Dónde o para quién se fue el dinero? (gastos del mes por lugar/persona)
        $byPlace = (clone $expenses)->whereNotNull('place')
            ->selectRaw('place, SUM(amount) as total, COUNT(*) as n')->groupBy('place')->orderByDesc('total')->take(10)->get();
        $noPlace = (float) (clone $expenses)->whereNull('place')->sum('amount');

        // Gasto por persona de la familia, con sus categorías principales
        $personRows = (clone $expenses)->whereNotNull('person_id')->with('category.parent')->get()->groupBy('person_id');
        $byPerson = $h->people()->get()->map(function ($p) use ($personRows) {
            $rows = $personRows->get($p->id, collect());
            $cats = $rows->groupBy(fn ($t) => $t->category?->parent?->name ?? $t->category?->name ?? 'Sin categoría')
                ->map(fn ($g) => $g->sum('amount'))->sortDesc()->take(3);

            return ['person' => $p, 'total' => (float) $rows->sum('amount'), 'n' => $rows->count(), 'cats' => $cats];
        })->filter(fn ($x) => $x['total'] > 0)->sortByDesc('total')->values();

        $biggest = (clone $expenses)->with('category.parent')->orderByDesc('amount')->take(5)->get();

        return view('reports.index', compact('month', 'summary', 'prev', 'fixedPaid', 'variable', 'fixedRows', 'cats', 'uncategorized', 'incomeCats', 'incomeUncategorized', 'byPlace', 'noPlace', 'byPerson', 'trend', 'biggest') + [
            'fixedTotals' => $fixedSvc->totals($fixedRows),
        ]);
    }

    /** CSV de movimientos del mes (compatible con Excel). */
    public function export(Request $r)
    {
        $h = $this->household($r);
        $month = $this->month($r);
        $us = in_array($h->country, ['US', 'MX'], true);
        $sep = $us ? ',' : ';';
        $num = fn ($n) => $us ? number_format((float) $n, 2, '.', '') : number_format((float) $n, 2, ',', '');

        $rows = $h->transactions()->with('category.parent', 'user', 'person')
            ->whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->orderBy('date')->orderBy('id')->get();

        $name = 'finanve-'.$month->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($rows, $sep, $num, $h) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Tipo', 'Categoría', 'Subcategoría', 'Nota', 'Lugar', 'Persona', 'Tu parte ('.$h->currency().')', 'Total del gasto', 'Gasto fijo', 'Registrado por'], $sep);
            foreach ($rows as $t) {
                $c = $t->category;
                fputcsv($out, [
                    $t->date->format('Y-m-d'), ['income' => 'Ingreso', 'saving' => 'Ahorro'][$t->type] ?? 'Gasto',
                    $c?->parent?->name ?? $c?->name ?? '', $c?->parent_id ? $c->name : '',
                    $t->note ?? '', $t->place ?? '', $t->person?->name ?? '', $num($t->amount), $num($t->total_amount ?? $t->amount), $t->fixed_month ? 'Sí' : 'No', $t->user->name,
                ], $sep);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
