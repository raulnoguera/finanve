<?php

namespace App\Http\Controllers;

use App\Services\FinanceService;
use App\Services\FixedExpenseService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $r, FinanceService $fin, FixedExpenseService $fixedSvc)
    {
        $h = $this->household($r);
        $month = $this->month($r);
        $summary = $fin->month($h, $month);
        $prev = $fin->month($h, $month->copy()->subMonth());
        $surplus = $fin->averageMonthlySurplus($h);

        $goals = $h->goals()->whereNull('completed_at')->orderBy('deadline')->take(3)->get()
            ->map(fn ($g) => ['goal' => $g] + $fin->goalStatus($g, $surplus));

        $budgets = $h->categories()->with('parent')->where('type', 'expense')->where('monthly_budget', '>', 0)->get();
        $spent = $fin->spentRollup($h, $month);

        $debts = $h->debts()->incurred()->whereNull('settled_at')->get();
        $owe = $debts->where('kind', 'owe')->sum(fn ($d) => $d->remaining());
        $lent = $debts->where('kind', 'lent')->sum(fn ($d) => $d->remaining());

        $fixedRows = $fixedSvc->month($h, $month);
        $fixedTotals = $fixedSvc->totals($fixedRows);
        // Mes anterior con fijos sin pagar (solo cuando miramos el mes en curso)
        $prevUnpaid = $month->isSameMonth(now())
            ? $fixedSvc->month($h, $month->copy()->subMonthNoOverflow())->where('status', '!=', 'paid')->count()
            : 0;

        $forecast = $fin->forecast($h, $month, (float) $summary['balance'], (float) ($fixedTotals['pending'] ?? 0));
        $ahead = $fin->budgetAhead($month, $budgets, $spent, $fixedRows->where('status', '!=', 'paid'), $forecast['planned_items']);
        $budgetTotal = (float) $budgets->sum('monthly_budget');
        $hasBudget = $budgets->isNotEmpty();

        $sharedPending = collect();
        $linked = $r->user()->linkedPeople()->pluck('id');
        if ($linked->isNotEmpty()) {
            $sharedPending = \App\Models\Debt::whereIn('person_id', $linked)->whereIn('kind', ['lent', 'shared'])->whereNull('settled_at')
                ->with('payments', 'linkedPerson.household')->get()
                ->groupBy(fn ($d) => $d->linkedPerson->household->currency())
                ->map(fn ($g, $cur) => \App\Support\Money::format($g->sum(fn ($d) => $d->remaining()), $cur))->values();
        }

        $goalsCount = $h->goals()->count();
        $lastMonth = $month->copy()->subMonthNoOverflow();
        $lastMonthLabel = mes_es($lastMonth);

        $recent = $h->transactions()->with('category.parent', 'user', 'person', 'goal')->latest('date')->latest('id')->take(6)->get();

        return view('dashboard', compact('forecast', 'ahead', 'budgetTotal', 'hasBudget', 'month', 'summary', 'prev', 'surplus', 'goals', 'budgets', 'spent', 'owe', 'lent', 'recent', 'fixedRows', 'fixedTotals', 'prevUnpaid', 'sharedPending', 'goalsCount', 'lastMonth', 'lastMonthLabel'));
    }
}
