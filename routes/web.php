<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FixedExpenseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\RateController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SharedController;
use App\Http\Controllers\ShareRequestController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/entrar', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/registro/{token?}', [AuthController::class, 'registerForm'])->name('register');
    Route::post('/registro', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register.store');

    Route::get('/olvide-mi-clave', [PasswordController::class, 'forgot'])->name('password.request');
    Route::post('/olvide-mi-clave', [PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/restablecer/{token}', [PasswordController::class, 'resetForm'])->name('password.reset');
    Route::post('/restablecer', [PasswordController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/salir', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/movimientos', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/movimientos', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('/movimientos/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::post('/movimientos/{transaction}/compartir', [ShareController::class, 'store'])->name('transactions.share');
    Route::post('/movimientos/{transaction}/confirmar', [TransactionController::class, 'confirm'])->name('transactions.confirm');
    Route::delete('/movimientos/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');

    Route::get('/mercado', [MarketController::class, 'index'])->name('market.index');
    Route::post('/mercado', [MarketController::class, 'store'])->name('market.store');

    Route::get('/presupuesto', [BudgetController::class, 'index'])->name('budget.index');
    Route::post('/categorias', [BudgetController::class, 'store'])->name('categories.store');
    Route::post('/categorias/sugeridas', [BudgetController::class, 'suggested'])->name('categories.suggested');
    Route::patch('/categorias/{category}', [BudgetController::class, 'update'])->name('categories.update');
    Route::delete('/categorias/{category}', [BudgetController::class, 'destroy'])->name('categories.destroy');

    Route::get('/gastos-fijos', [FixedExpenseController::class, 'index'])->name('fixed.index');
    Route::post('/gastos-fijos', [FixedExpenseController::class, 'store'])->name('fixed.store');
    Route::patch('/gastos-fijos/{fixed}', [FixedExpenseController::class, 'update'])->name('fixed.update');
    Route::delete('/gastos-fijos/{fixed}', [FixedExpenseController::class, 'destroy'])->name('fixed.destroy');
    Route::post('/gastos-fijos/{fixed}/pagar', [FixedExpenseController::class, 'pay'])->name('fixed.pay');
    Route::delete('/gastos-fijos/{fixed}/pagos/{month}', [FixedExpenseController::class, 'unpay'])->name('fixed.unpay')->where('month', '\d{4}-\d{2}(-\d{2})?');

    Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reportes/exportar', [ReportController::class, 'export'])->name('reports.export');

    Route::get('/metas', [GoalController::class, 'index'])->name('goals.index');
    Route::post('/metas', [GoalController::class, 'store'])->name('goals.store');
    Route::post('/metas/{goal}/aportes', [GoalController::class, 'contribute'])->name('goals.contribute');
    Route::delete('/metas/{goal}/aportes/{contribution}', [GoalController::class, 'removeContribution'])->name('goals.contribution.remove');
    Route::delete('/metas/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

    Route::get('/deudas', [DebtController::class, 'index'])->name('debts.index');
    Route::post('/deudas', [DebtController::class, 'store'])->name('debts.store');
    Route::post('/deudas/{debt}/pagos', [DebtController::class, 'pay'])->name('debts.pay');
    Route::patch('/deudas/{debt}', [DebtController::class, 'update'])->name('debts.update');
    Route::post('/deudas/{debt}/detalles', [DebtController::class, 'itemStore'])->name('debts.items.store');
    Route::delete('/deudas/{debt}/detalles/{item}', [DebtController::class, 'itemDestroy'])->name('debts.items.destroy');
    Route::post('/deudas/{debt}/detalles/{item}/pagar', [DebtController::class, 'itemPay'])->name('debts.items.pay');
    Route::delete('/deudas/{debt}/pagos/{payment}', [DebtController::class, 'unpay'])->name('debts.unpay');
    Route::delete('/deudas/{debt}', [DebtController::class, 'destroy'])->name('debts.destroy');

    Route::post('/personas', [PersonController::class, 'store'])->name('people.store');
    Route::patch('/personas/{person}', [PersonController::class, 'update'])->name('people.update');
    Route::delete('/personas/{person}', [PersonController::class, 'destroy'])->name('people.destroy');
    Route::post('/personas/{person}/invitar', [PersonController::class, 'invite'])->name('people.invite');
    Route::delete('/personas/{person}/vinculo', [PersonController::class, 'unlink'])->name('people.unlink');

    Route::get('/compartidos', [SharedController::class, 'index'])->name('shared.index');
    Route::post('/compartidos/{debt}/pagar', [SharedController::class, 'pay'])->name('shared.pay');
    Route::post('/solicitudes/{invitation}/aceptar', [ShareRequestController::class, 'accept'])->name('requests.accept');
    Route::post('/solicitudes/{invitation}/rechazar', [ShareRequestController::class, 'decline'])->name('requests.decline');
    Route::get('/compartido/{token}', [AuthController::class, 'acceptShare'])->name('share.accept');

    Route::get('/familia', [FamilyController::class, 'index'])->name('family.index');
    Route::post('/familia/invitar', [FamilyController::class, 'invite'])->name('family.invite');
    Route::patch('/familia', [FamilyController::class, 'rename'])->name('family.rename');
    Route::delete('/familia/invitaciones/{invitation}', [FamilyController::class, 'revoke'])->name('family.revoke');
    Route::delete('/familia/miembros/{user}', [FamilyController::class, 'remove'])->name('family.remove');

    Route::patch('/preferencias', [PasswordController::class, 'preferences'])->name('preferences.update');

    Route::post('/tasa/actualizar', [RateController::class, 'refresh'])->name('rate.refresh');
    Route::post('/tasa/manual', [RateController::class, 'manual'])->name('rate.manual');
});
