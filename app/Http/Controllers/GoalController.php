<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\GoalContribution;
use App\Services\GoalAdvisor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    public function index(Request $r, GoalAdvisor $advisor)
    {
        $h = $this->household($r);

        $goals = $h->goals()->with('contributions.user')->orderByRaw('completed_at is null desc')->orderBy('deadline')->get();
        $advice = $advisor->plans($h, $goals);

        return view('goals.index', [
            'goals' => $goals, 'plans' => $advice['plans'], 'ctx' => $advice['context'],
            'committed' => $advice['committed'], 'overcommitted' => $advice['overcommitted'], 'sumNeeds' => $advice['sum_needs'],
        ]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:60'],
            'target_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'deadline' => ['nullable', 'date', 'after:today'],
        ]);

        $this->household($r)->goals()->create($data);

        return back()->with('ok', 'Meta creada. Mira abajo el plan realista para llegar a ella.');
    }

    /** Aporte a una meta: queda en el historial de la meta Y como movimiento de ahorro (reduce lo que te queda). */
    public function contribute(Request $r, Goal $goal)
    {
        $this->own($r, $goal);
        $h = $this->household($r);

        $data = $r->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'currency' => ['required', Rule::in(currency_codes())],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:140'],
        ]);

        [$amount, $rate] = $this->toUsd((float) $data['amount'], $data['currency']);

        DB::transaction(function () use ($goal, $h, $r, $data, $amount, $rate) {
            $tx = $h->transactions()->create([
                'user_id' => $r->user()->id, 'goal_id' => $goal->id, 'type' => 'saving',
                'amount' => $amount, 'currency' => $data['currency'], 'original_amount' => $data['amount'], 'rate' => $rate,
                'date' => $data['date'], 'note' => $data['note'] ?? 'Aporte a la meta: '.$goal->name,
            ]);
            $goal->contributions()->create([
                'user_id' => $r->user()->id, 'transaction_id' => $tx->id, 'amount' => $amount,
                'date' => $data['date'], 'note' => $data['note'] ?? null,
            ]);
            $this->refreshCompletion($goal);
        });

        return back()->with('ok', 'Aporte registrado en «'.$goal->name.'» y en tus movimientos: '.money($amount).'.');
    }

    /** Elimina un aporte (y su movimiento de ahorro). */
    public function removeContribution(Request $r, Goal $goal, GoalContribution $contribution)
    {
        $this->own($r, $goal);
        abort_unless($contribution->goal_id === $goal->id, 404);

        DB::transaction(function () use ($goal, $contribution) {
            $tx = $contribution->transaction;
            $contribution->delete();
            $tx?->delete();
            $this->refreshCompletion($goal);
        });

        return back()->with('ok', 'Aporte eliminado.');
    }

    private function refreshCompletion(Goal $goal): void
    {
        $done = $goal->savedAmount() >= (float) $goal->target_amount;
        $goal->update(['completed_at' => $done ? ($goal->completed_at ?? now()) : null]);
    }

    public function destroy(Request $r, Goal $goal)
    {
        $this->own($r, $goal);

        // Los aportes ya apartados vuelven a "lo que te queda": se eliminan sus movimientos de ahorro
        DB::transaction(function () use ($goal) {
            $goal->household->transactions()->where('goal_id', $goal->id)->where('type', 'saving')->delete();
            $goal->delete();
        });

        return back()->with('ok', 'Meta eliminada. Lo que habías apartado vuelve a contar como disponible.');
    }
}
