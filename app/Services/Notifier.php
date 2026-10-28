<?php

namespace App\Services;

use App\Models\Household;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/** Envía avisos por correo sin que un fallo de SMTP rompa la petición del usuario. */
class Notifier
{
    /** Avisos opcionales: solo a miembros que no los desactivaron. */
    public function household(Household $h, Notification $n): void
    {
        $users = $h->users()->where('notify_email', true)->get();

        $this->safely(fn () => NotificationFacade::send($users, $n));
    }

    public function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** true la primera vez que se pide esa clave dentro del periodo (evita avisos repetidos). */
    public function once(string $key, int $days = 40): bool
    {
        return Cache::add('notified:'.$key, true, now()->addDays($days));
    }

    /** Revisa si un gasto lleva su categoría (y la categoría madre, con roll-up) al 80 % o 100 % del límite y avisa una sola vez. */
    public function budgetCheck(Household $h, ?int $categoryId, \Carbon\Carbon $date): void
    {
        $cat = $categoryId ? $h->categories()->with('parent')->find($categoryId) : null;
        if (! $cat) {
            return;
        }

        foreach (array_filter([$cat, $cat->parent]) as $c) {
            $this->checkOne($h, $c, $date);
        }
    }

    private function checkOne(Household $h, \App\Models\Category $cat, \Carbon\Carbon $date): void
    {
        $budget = (float) $cat->monthly_budget;
        if ($budget <= 0) {
            return;
        }

        $ids = $cat->children()->pluck('id')->push($cat->id);
        $spent = (float) $h->transactions()->where('type', 'expense')->whereIn('category_id', $ids)
            ->whereBetween('date', [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()])->sum('amount');
        $level = $spent >= $budget ? 'over' : ($spent >= $budget * 0.8 ? 'near' : null);

        if ($level && $this->once("budget:{$cat->id}:{$date->format('Y-m')}:{$level}")) {
            $this->household($h, new \App\Notifications\BudgetAlertNotification($cat->fullName(), $spent, $budget, $level, $h->currency()));
        }
    }
}
