<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function index(Request $r, FinanceService $fin)
    {
        $h = $this->household($r);
        $month = $this->month($r);

        return view('budget.index', [
            'month' => $month,
            'expense' => $h->categories()->with('children')->whereNull('parent_id')->where('type', 'expense')->orderBy('name')->get(),
            'income' => $h->categories()->with('children')->whereNull('parent_id')->where('type', 'income')->orderBy('name')->get(),
            'incomeSums' => $fin->incomeByCategory($h, $month),
            'direct' => $fin->spentByCategory($h, $month),
            'rollup' => $fin->spentRollup($h, $month),
        ]);
    }

    public function store(Request $r)
    {
        $h = $this->household($r);

        $data = $r->validate([
            'name' => ['required', 'string', 'max:40'],
            'type' => ['required_without:parent_id', Rule::in(['income', 'expense'])],
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('household_id', $h->id)->whereNull('parent_id')],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'monthly_budget' => ['nullable', 'numeric', 'min:0'],
        ]);

        $parent = ! empty($data['parent_id']) ? $h->categories()->find($data['parent_id']) : null;
        $type = $parent?->type ?? $data['type'];
        $name = trim($data['name']);

        $dup = $h->categories()->where('type', $type)->where('parent_id', $parent?->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($dup) {
            return back()->withErrors(['name' => "Ya existe una categoría llamada «{$name}»."])->withInput();
        }

        $palette = ['#10b981', '#14b8a6', '#06b6d4', '#0ea5e9', '#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#84cc16', '#f97316'];

        $h->categories()->create([
            'name' => $name,
            'type' => $type,
            'parent_id' => $parent?->id,
            'color' => $parent?->color ?? ($data['color'] ?? $palette[crc32(mb_strtolower($name)) % count($palette)]),
            'icon' => $parent?->icon ?? 'tag',
            'monthly_budget' => $data['monthly_budget'] ?? null,
        ]);

        return back()->with('ok', $parent ? "Subcategoría creada en {$parent->name}." : ($type === 'income' ? "Categoría de ingreso «{$name}» creada." : 'Categoría creada.'));
    }

    public function suggested(Request $r)
    {
        $type = in_array($r->input('type'), ['income', 'expense'], true) ? $r->input('type') : null;
        $n = $this->household($r)->seedSubcategories($type);

        return back()->with('ok', $n ? "Agregamos {$n} subcategorías sugeridas." : 'Ya tienes todas las subcategorías sugeridas.');
    }

    public function update(Request $r, Category $category)
    {
        $this->own($r, $category);

        $data = $r->validate([
            'name' => ['sometimes', 'required', 'string', 'max:40'],
            'monthly_budget' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['name'])) {
            $dup = $category->household->categories()->where('id', '!=', $category->id)->where('type', $category->type)
                ->where('parent_id', $category->parent_id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->exists();
            if ($dup) {
                return back()->withErrors(['name' => 'Ya existe una categoría con ese nombre.']);
            }
        }

        $category->update($data);

        return back()->with('ok', 'Categoría actualizada.');
    }

    public function destroy(Request $r, Category $category)
    {
        $this->own($r, $category)->delete();

        return back()->with('ok', 'Categoría eliminada.');
    }
}
