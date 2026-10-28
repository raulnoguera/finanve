<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Household;
use App\Models\User;
use App\Notifications\BudgetAlertNotification;
use App\Notifications\DebtReminderNotification;
use App\Notifications\FixedUnpaidNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\ParticipantInvitationNotification;
use App\Notifications\ShareReceivedNotification;
use App\Notifications\SharedDueReminderNotification;
use App\Notifications\SharedPaymentReportedNotification;
use App\Notifications\MonthlySummaryNotification;
use App\Notifications\ResetPasswordEs;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FinanzasTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        ExchangeRate::create(['source' => 'oficial', 'rate' => 100, 'fetched_at' => now()]);
        $h = Household::create(['name' => 'Familia T']);
        $h->seedCategories();

        return User::create(['name' => 'Ana Perez', 'email' => 'ana@example.test', 'password' => 'password-123', 'household_id' => $h->id, 'role' => 'owner']);
    }

    public function test_registro_crea_familia_con_categorias(): void
    {
        ExchangeRate::create(['source' => 'oficial', 'rate' => 100, 'fetched_at' => now()]);

        $this->post('/registro', ['name' => 'Luis Gómez', 'email' => 'luis@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'country' => 'CO'])
            ->assertRedirect('/');

        $u = User::first();
        $this->assertSame('owner', $u->role);
        $this->assertSame('CO', $u->household->country);
        $this->assertGreaterThan(5, $u->household->categories()->count());
    }

    public function test_registro_pide_pais_primero_y_muestra_banderas(): void
    {
        $this->get('/registro')->assertOk()->assertSee('¿Desde qué país')->assertSee('Venezuela')->assertSee('Chile')->assertSee('España')->assertSee('México');
        $this->get('/registro?pais=ES')->assertOk()->assertSee('Usarás Finanve desde')->assertSee('España');
        $this->get('/registro?pais=XX')->assertOk()->assertSee('¿Desde qué país');
    }

    public function test_invitacion_une_al_familiar_a_la_misma_familia(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/familia/invitar')->assertSessionHas('link');
        $inv = $owner->household->invitations()->first();

        auth()->logout();
        $this->get('/registro/'.$inv->token)->assertOk()->assertSee('te invitó');
        $this->post('/registro', ['name' => 'Mamá', 'email' => 'mama@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'token' => $inv->token])
            ->assertRedirect('/');

        $member = User::where('email', 'mama@example.test')->first();
        $this->assertSame($owner->household_id, $member->household_id);
        $this->assertSame('member', $member->role);
        $this->assertNotNull($inv->fresh()->accepted_at);

        // El enlace es de un solo uso
        auth()->logout();
        $this->get('/registro/'.$inv->token)->assertRedirect('/registro');
    }

    public function test_gasto_en_bolivares_se_guarda_en_dolares(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/movimientos', ['type' => 'expense', 'amount' => 5000, 'currency' => 'VES', 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $t = $owner->household->transactions()->first();
        $this->assertSame('50.00', (string) $t->amount);
        $this->assertSame('VES', $t->currency);
    }

    public function test_fuera_de_venezuela_se_usa_la_moneda_local_sin_bolivares(): void
    {
        ExchangeRate::create(['source' => 'oficial', 'rate' => 100, 'fetched_at' => now()]);
        $h = Household::create(['name' => 'Familia CO', 'country' => 'CO']);
        $h->seedCategories();
        $u = User::create(['name' => 'Camila R', 'email' => 'cami@example.test', 'password' => 'password-123', 'household_id' => $h->id, 'role' => 'owner']);

        $this->actingAs($u)->post('/movimientos', ['type' => 'expense', 'amount' => 45000, 'currency' => 'COP', 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $t = $h->transactions()->first();
        $this->assertSame('45000.00', (string) $t->amount);   // sin conversión
        $this->assertSame('COP', $t->currency);
        $this->assertNull($t->rate);

        // Bolívares no son aceptados fuera de Venezuela
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 10, 'currency' => 'VES', 'date' => now()->toDateString()])
            ->assertSessionHasErrors('currency');

        $this->assertSame('$45.000', money(45000));
        $this->get('/')->assertOk()->assertSee('$45.000')->assertDontSee('Bs ');
        $this->get('/metas')->assertOk()->assertSee('Monto (COP)');

        // Pesos mexicanos, euros y pesos chilenos
        $h->update(['country' => 'MX']);
        $this->actingAs($u->fresh());
        $this->assertSame('$1,234.50', money(1234.5));
        $h->update(['country' => 'ES']);
        $this->actingAs($u->fresh());
        $this->assertSame('1.234,50 €', money(1234.5));
        $h->update(['country' => 'CL']);
        $this->actingAs($u->fresh());
        $this->assertSame('$1.234.568', money(1234567.8));
    }

    public function test_no_se_ven_datos_de_otra_familia(): void
    {
        $a = $this->owner();
        $tx = $a->household->transactions()->create(['user_id' => $a->id, 'type' => 'expense', 'amount' => 10, 'original_amount' => 10, 'currency' => 'USD', 'date' => now()]);

        $otra = Household::create(['name' => 'Otra']);
        $b = User::create(['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'password-123', 'household_id' => $otra->id, 'role' => 'owner']);

        $this->actingAs($b)->delete('/movimientos/'.$tx->id)->assertNotFound();
        $this->assertDatabaseHas('transactions', ['id' => $tx->id]);
    }

    public function test_meta_proyecta_segun_ahorro_y_pago_de_deuda_la_salda(): void
    {
        $owner = $this->owner();
        $h = $owner->household;

        $goal = $h->goals()->create(['name' => 'Viaje', 'target_amount' => 100, 'deadline' => now()->addMonths(4)]);
        $this->actingAs($owner)->post("/metas/{$goal->id}/aportes", ['amount' => 100, 'currency' => 'USD', 'date' => now()->toDateString()]);
        $this->assertNotNull($goal->fresh()->completed_at);

        $debt = $h->debts()->create(['user_id' => $owner->id, 'kind' => 'owe', 'person' => 'Banco', 'amount' => 40]);
        $this->actingAs($owner)->post("/deudas/{$debt->id}/pagos", ['amount' => 40, 'currency' => 'USD']);
        $this->assertNotNull($debt->fresh()->settled_at);

        foreach (['/', '/movimientos', '/presupuesto', '/metas', '/deudas', '/familia'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_bienvenida_e_invitacion_por_correo(): void
    {
        Notification::fake();
        ExchangeRate::create(['source' => 'oficial', 'rate' => 100, 'fetched_at' => now()]);

        $this->post('/registro', ['name' => 'Ana Perez', 'email' => 'ana@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'country' => 'VE']);
        $ana = User::where('email', 'ana@example.test')->first();
        Notification::assertSentTo($ana, WelcomeNotification::class);

        $this->post('/familia/invitar', ['email' => 'mama@example.test'])->assertSessionHas('ok');
        Notification::assertSentOnDemand(InvitationNotification::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'mama@example.test');
    }

    public function test_recuperar_contrasena_envia_correo_y_permite_cambiarla(): void
    {
        Notification::fake();
        $u = $this->owner();

        $this->post('/olvide-mi-clave', ['email' => $u->email])->assertSessionHas('ok');
        // no revela si el correo existe
        $this->post('/olvide-mi-clave', ['email' => 'nadie@example.test'])->assertSessionHas('ok');

        $token = null;
        Notification::assertSentTo($u, ResetPasswordEs::class, function ($n) use (&$token) { $token = $n->token; return true; });

        $this->post('/restablecer', ['token' => $token, 'email' => $u->email, 'password' => 'nueva-clave-456', 'password_confirmation' => 'nueva-clave-456'])
            ->assertRedirect('/entrar');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('nueva-clave-456', $u->fresh()->password));
    }

    public function test_alerta_de_presupuesto_80_y_100_una_sola_vez_y_respeta_preferencia(): void
    {
        Notification::fake();
        $u = $this->owner();
        $cat = $u->household->categories()->where('name', 'Comida')->first();
        $cat->update(['monthly_budget' => 100]);
        $gasto = fn ($n) => $this->actingAs($u)->post('/movimientos', ['type' => 'expense', 'amount' => $n, 'currency' => 'USD', 'category_id' => $cat->id, 'date' => now()->toDateString()]);

        $gasto(50);   // 50 %: sin aviso
        Notification::assertNothingSent();
        $gasto(35);   // 85 %: aviso "near"
        $gasto(1);    // 86 %: no repite
        Notification::assertSentToTimes($u, BudgetAlertNotification::class, 1);
        $gasto(20);   // 106 %: aviso "over"
        Notification::assertSentToTimes($u, BudgetAlertNotification::class, 2);

        // Con avisos desactivados no se envía nada nuevo
        $u->update(['notify_email' => false]);
        $cat2 = $u->household->categories()->where('name', 'Ocio')->first();
        $cat2->update(['monthly_budget' => 10]);
        $this->actingAs($u->fresh())->post('/movimientos', ['type' => 'expense', 'amount' => 50, 'currency' => 'USD', 'category_id' => $cat2->id, 'date' => now()->toDateString()]);
        Notification::assertSentToTimes($u, BudgetAlertNotification::class, 2);
    }

    public function test_recordatorio_de_deudas_y_resumen_mensual(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $h->debts()->create(['user_id' => $u->id, 'kind' => 'owe', 'person' => 'Banco', 'amount' => 100, 'due_date' => today()->addDays(3)]);
        $h->debts()->create(['user_id' => $u->id, 'kind' => 'lent', 'person' => 'Lejano', 'amount' => 50, 'due_date' => today()->addDays(20)]);

        $this->artisan('notify:debts')->assertSuccessful();
        Notification::assertSentTo($u, DebtReminderNotification::class);
        $this->artisan('notify:debts')->assertSuccessful();   // no se repite
        Notification::assertSentToTimes($u, DebtReminderNotification::class, 1);

        $h->transactions()->create(['user_id' => $u->id, 'type' => 'income', 'amount' => 500, 'original_amount' => 500, 'currency' => 'USD', 'date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2)]);
        $this->artisan('notify:monthly')->assertSuccessful();
        Notification::assertSentTo($u, MonthlySummaryNotification::class);
    }

    public function test_el_correo_se_renderiza_con_logo_y_montos(): void
    {
        $u = $this->owner();
        $html = (string) (new BudgetAlertNotification('Comida', 85, 100, 'near', 'COP'))->toMail($u)->render();
        $this->assertStringContainsString('brand/logo-email.png', $html);
        $this->assertStringContainsString('$85', $html);
    }

    public function test_subcategorias_sugeridas_creacion_y_limites(): void
    {
        $u = $this->owner();
        $h = $u->household;

        // El registro ya trae subcategorías sugeridas (Comida › Restaurantes)
        $comida = $h->categories()->where('name', 'Comida')->whereNull('parent_id')->first();
        $this->assertNotNull($h->categories()->where('parent_id', $comida->id)->where('name', 'Restaurantes')->first());
        $this->assertSame(0, $h->seedSubcategories());   // idempotente

        // Crear una subcategoría propia: hereda tipo y color de la madre
        $this->actingAs($u)->post('/categorias', ['name' => 'Restaurante El Fogón', 'parent_id' => $comida->id])->assertSessionHasNoErrors();
        $fogon = $h->categories()->where('name', 'Restaurante El Fogón')->first();
        $this->assertSame($comida->id, $fogon->parent_id);
        $this->assertSame('expense', $fogon->type);
        $this->assertSame($comida->color, $fogon->color);
        $this->assertSame('Comida › Restaurante El Fogón', $fogon->fullName());

        // Un solo nivel: no se puede colgar una subcategoría de otra subcategoría
        $this->post('/categorias', ['name' => 'Nieta', 'parent_id' => $fogon->id])->assertSessionHasErrors('parent_id');

        // No se puede usar una categoría madre de otra familia
        $otra = Household::create(['name' => 'Otra']);
        $otra->seedCategories();
        $ajena = $otra->categories()->whereNull('parent_id')->first();
        $this->post('/categorias', ['name' => 'X', 'parent_id' => $ajena->id])->assertSessionHasErrors('parent_id');

        $this->get('/presupuesto')->assertOk()->assertSee('Restaurante El Fogón')->assertSee('Subcategoría');
    }

    public function test_gasto_en_subcategoria_suma_a_la_madre_y_dispara_su_alerta(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $comida = $h->categories()->where('name', 'Comida')->whereNull('parent_id')->first();
        $rest = $h->categories()->where('parent_id', $comida->id)->where('name', 'Restaurantes')->first();
        $comida->update(['monthly_budget' => 100]);
        $rest->update(['monthly_budget' => 40]);

        $gasto = fn ($n, $cat) => $this->actingAs($u)->post('/movimientos', ['type' => 'expense', 'amount' => $n, 'currency' => 'USD', 'category_id' => $cat->id, 'date' => now()->toDateString()]);

        $gasto(30, $comida);   // directo en la madre: 30 %
        $gasto(45, $rest);     // restaurantes 45/40 (over) y madre 75/100: solo avisa de la subcategoría
        Notification::assertSentToTimes($u, BudgetAlertNotification::class, 1);
        $gasto(10, $rest);     // madre 85/100: aviso "near" de la madre
        Notification::assertSentToTimes($u, BudgetAlertNotification::class, 2);

        $fin = app(\App\Services\FinanceService::class);
        $roll = $fin->spentRollup($h, now());
        $this->assertSame(85.0, $roll[$comida->id]);
        $this->assertSame(55.0, $roll[$rest->id]);

        // El gráfico agrupa por categoría principal
        $names = $fin->month($h, now())['by_category']->pluck('name')->all();
        $this->assertSame(['Comida'], $names);
    }

    public function test_reasignar_categoria_de_un_movimiento(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $comida = $h->categories()->where('name', 'Comida')->whereNull('parent_id')->first();
        $rest = $h->categories()->where('parent_id', $comida->id)->first();
        $tx = $h->transactions()->create(['user_id' => $u->id, 'category_id' => $comida->id, 'type' => 'expense', 'amount' => 20, 'original_amount' => 20, 'currency' => 'USD', 'date' => now()]);

        $this->actingAs($u)->patch("/movimientos/{$tx->id}", ['category_id' => $rest->id])->assertSessionHasNoErrors();
        $this->assertSame($rest->id, $tx->fresh()->category_id);

        // No puede pasar un gasto a una categoría de ingreso
        $salario = $h->categories()->where('type', 'income')->first();
        $this->patch("/movimientos/{$tx->id}", ['category_id' => $salario->id])->assertSessionHasErrors('category_id');

        $this->get('/movimientos')->assertOk()->assertSee('Comida › '.$rest->name);
    }

    public function test_lista_del_mercado_se_guarda_como_gasto_con_detalle(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $mercado = $h->categories()->where('name', 'Mercado')->whereNull('parent_id')->first();
        $mercado->update(['monthly_budget' => 50]);

        $this->actingAs($u)->get('/mercado')->assertOk()->assertSee('¿Cómo quieres registrar tu compra?')->assertSee('Solo el total de la factura')->assertSee('Detallar los productos')->assertSee('Total aproximado');

        $items = json_encode([
            ['name' => 'Arroz 1kg', 'qty' => 2, 'price' => 3.5],
            ['name' => 'Pollo', 'qty' => 1, 'price' => 12],
            ['name' => str_repeat('x', 200), 'qty' => 9999, 'price' => 1],   // se recorta y se limita
            ['name' => 'inválido', 'qty' => 1, 'price' => 'abc'],             // se descarta
        ]);

        $this->post('/mercado', ['amount' => 45.5, 'category_id' => $mercado->id, 'date' => now()->toDateString(), 'items_json' => $items])
            ->assertRedirect('/mercado')->assertSessionHas('market_saved');

        $tx = $h->transactions()->first();
        $this->assertSame('expense', $tx->type);
        $this->assertSame('45.50', (string) $tx->amount);
        $this->assertCount(3, $tx->items);
        $this->assertSame(999, $tx->items[2]['qty']);
        $this->assertSame(60, mb_strlen($tx->items[2]['name']));
        $this->assertStringContainsString('3 productos', $tx->note);

        // 45.5 de 50 = 91 %: dispara la alerta del presupuesto de Mercado
        Notification::assertSentTo($u, BudgetAlertNotification::class);

        // El detalle se ve en Movimientos
        $this->get('/movimientos')->assertOk()->assertSee('3 productos')->assertSee('Arroz 1kg');

        // Validaciones
        $this->post('/mercado', ['amount' => 0, 'date' => now()->toDateString()])->assertSessionHasErrors('amount');
    }

    public function test_menu_movil_incluye_mercado_y_acceso_a_familia(): void
    {
        $u = $this->owner();
        $html = $this->actingAs($u)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Mercado', $html);
        $this->assertStringContainsString('role="menu"', $html);
        $this->assertStringContainsString(route('family.index'), $html);
    }

    public function test_gastos_fijos_se_precargan_cada_mes_y_se_marcan_pagados(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $vivienda = $h->categories()->where('name', 'Vivienda')->whereNull('parent_id')->first();

        $this->actingAs($u)->post('/gastos-fijos', ['name' => 'Alquiler', 'amount' => 300, 'due_day' => 5, 'category_id' => $vivienda->id])
            ->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();
        $mes = now()->format('Y-m');

        // aparece pendiente/vencido en el mes en curso y NO cuenta como gasto aún
        $this->get('/gastos-fijos')->assertOk()->assertSee('Alquiler')->assertSee('Marcar pagado');
        $this->assertSame(0, $h->transactions()->count());

        // marcar pagado crea el gasto real con su categoría
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => $mes])->assertSessionHas('ok');
        $tx = $h->transactions()->first();
        $this->assertSame('300.00', (string) $tx->amount);
        $this->assertSame($vivienda->id, $tx->category_id);
        $this->assertSame($mes, $tx->fixed_month);
        $this->assertSame($fx->id, $tx->fixed_expense_id);

        // idempotente: no duplica
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => $mes]);
        $this->assertSame(1, $h->transactions()->count());

        // otro monto en un mes anterior (pago tardío)
        $ant = now()->subMonthNoOverflow()->format('Y-m');
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => $ant, 'amount' => 280]);
        $this->assertSame('280.00', (string) $h->transactions()->where('fixed_month', $ant)->first()->amount);

        // no se pueden pagar meses futuros
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => now()->addMonths(2)->format('Y-m')])->assertStatus(422);

        // deshacer pago
        $this->delete("/gastos-fijos/{$fx->id}/pagos/{$mes}");
        $this->assertSame(0, $h->transactions()->where('fixed_month', $mes)->count());

        // otra familia no puede tocarlo
        $otra = Household::create(['name' => 'Otra']);
        $b = User::create(['name' => 'Bob', 'email' => 'bob2@example.test', 'password' => 'password-123', 'household_id' => $otra->id, 'role' => 'owner']);
        $this->actingAs($b)->post("/gastos-fijos/{$fx->id}/pagar", ['month' => $mes])->assertNotFound();
    }

    public function test_aviso_por_correo_de_fijos_sin_pagar_al_pasar_el_mes(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Internet', 'amount' => 25, 'due_day' => 10, 'created_at' => now()->subMonths(3)]);
        $pagado = $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Agua', 'amount' => 10, 'due_day' => 12, 'created_at' => now()->subMonths(3)]);
        $ant = now()->subMonthNoOverflow();
        $h->transactions()->create(['user_id' => $u->id, 'type' => 'expense', 'amount' => 10, 'original_amount' => 10, 'currency' => 'USD',
            'date' => $ant->copy()->day(12), 'fixed_expense_id' => $pagado->id, 'fixed_month' => $ant->format('Y-m')]);

        $this->artisan('notify:fixed')->assertSuccessful();
        Notification::assertSentTo($u, FixedUnpaidNotification::class, function ($n, $ch) use ($u) {
            $html = (string) $n->toMail($u)->render();
            return str_contains($html, 'Internet') && ! str_contains($html, 'Agua');
        });

        // no se repite
        $this->artisan('notify:fixed')->assertSuccessful();
        Notification::assertSentToTimes($u, FixedUnpaidNotification::class, 1);

        // si el usuario desactivó los avisos, no recibe
        Notification::fake();
        $u->update(['notify_email' => false]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->artisan('notify:fixed')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_reportes_separan_fijos_de_imprevistos_y_exportan_csv(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $fx = $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Alquiler', 'amount' => 200, 'due_day' => 1]);
        $com = $h->categories()->where('name', 'Comida')->whereNull('parent_id')->first();

        $h->transactions()->create(['user_id' => $u->id, 'type' => 'income', 'amount' => 1000, 'original_amount' => 1000, 'currency' => 'USD', 'date' => now()]);
        $h->transactions()->create(['user_id' => $u->id, 'type' => 'expense', 'amount' => 200, 'original_amount' => 200, 'currency' => 'USD', 'date' => now(),
            'fixed_expense_id' => $fx->id, 'fixed_month' => now()->format('Y-m'), 'note' => 'Alquiler']);
        $h->transactions()->create(['user_id' => $u->id, 'type' => 'expense', 'category_id' => $com->id, 'amount' => 100, 'original_amount' => 100, 'currency' => 'USD', 'date' => now(), 'note' => 'Cena; con "comillas"']);

        $this->actingAs($u)->get('/reportes')->assertOk()
            ->assertSee('Fijos vs. imprevistos')->assertSee('$200.00')->assertSee('$100.00')->assertSee('67%')->assertSee('Gasto por categoría');

        $csv = $this->get('/reportes/exportar')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('Fecha;Tipo;Categoría', $csv);
        $this->assertStringContainsString('Alquiler', $csv);
        $this->assertStringContainsString(';Sí;', $csv);
        $this->assertStringContainsString('"Cena; con ""comillas"""', $csv);
    }

    public function test_inicio_muestra_tarjetas_de_ingresos_y_gastos_fijos(): void
    {
        $u = $this->owner();
        $this->actingAs($u)->get('/')->assertOk()
            ->assertSee('Ingresa tu salario e ingresos')
            ->assertSee('Tus gastos fijos del mes')->assertDontSee('Registra tus gastos fijos del mes')
            ->assertSee(route('fixed.index', ['mes' => now()->format('Y-m')]), false);
    }

    public function test_mercado_guarda_lugar_y_permite_solo_el_total(): void
    {
        $u = $this->owner();
        $h = $u->household;

        // Solo el total de la factura, con el lugar (opcional)
        $this->actingAs($u)->post('/mercado', ['amount' => 87.4, 'currency' => 'USD', 'place' => 'Mercado municipal', 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $tx = $h->transactions()->first();
        $this->assertSame('87.40', (string) $tx->amount);
        $this->assertSame('Mercado municipal', $tx->place);
        $this->assertNull($tx->items);
        $this->assertSame('Compra en Mercado municipal', $tx->note);

        // Sin lugar ni detalle: sigue funcionando
        $this->post('/mercado', ['amount' => 10, 'date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('Mercado', $h->transactions()->latest('id')->first()->note);

        // Detalle con un solo producto: singular
        $this->post('/mercado', ['amount' => 5, 'place' => 'Gama', 'date' => now()->toDateString(), 'items_json' => json_encode([['name' => 'Pan', 'qty' => 1, 'price' => 5]])]);
        $this->assertSame('Compra en Gama · 1 producto', $h->transactions()->latest('id')->first()->note);

        // Se ve en Movimientos y en el CSV
        $this->get('/movimientos')->assertOk()->assertSee('en Mercado municipal');
        $this->get('/reportes/exportar')->assertOk();
        $this->assertStringContainsString('Lugar', $this->get('/reportes/exportar')->streamedContent());

        // El lugar se sugiere la próxima vez
        $this->get('/mercado')->assertOk()->assertSee('Mercado municipal')->assertSee('Gama');
    }

    public function test_se_pueden_agregar_categorias_de_ingreso(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);

        // Desde Presupuesto
        $this->post('/categorias', ['name' => 'Remesas', 'type' => 'income'])->assertSessionHasNoErrors();
        $rem = $h->categories()->where('name', 'Remesas')->first();
        $this->assertSame('income', $rem->type);
        $this->assertNotEmpty($rem->color);

        // Sin duplicados (ignora mayúsculas)
        $this->post('/categorias', ['name' => 'remesas', 'type' => 'income'])->assertSessionHasErrors('name');
        $this->assertSame(1, $h->categories()->where('type', 'income')->whereNull('parent_id')->whereRaw("LOWER(name) = 'remesas'")->count());

        // Renombrar
        $this->patch("/categorias/{$rem->id}", ['name' => 'Remesas familiares'])->assertSessionHasNoErrors();
        $this->assertSame('Remesas familiares', $rem->fresh()->name);

        // Crear sobre la marcha al registrar un ingreso
        $this->post('/movimientos', ['type' => 'income', 'amount' => 150, 'currency' => 'USD', 'new_category' => 'Freelance', 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $free = $h->categories()->where('name', 'Freelance')->first();
        $this->assertNotNull($free);
        $this->assertSame('income', $free->type);
        $this->assertSame($free->id, $h->transactions()->latest('id')->first()->category_id);

        // Reutiliza la categoría si ya existe con ese nombre
        $this->post('/movimientos', ['type' => 'income', 'amount' => 80, 'currency' => 'USD', 'new_category' => 'freelance', 'date' => now()->toDateString()]);
        $this->assertSame(1, $h->categories()->whereNull('parent_id')->whereRaw("LOWER(name) = 'freelance'")->count());

        // Aparece en el formulario y en Presupuesto
        $this->get('/presupuesto')->assertOk()->assertSee('Categorías de ingreso')->assertSee('Remesas familiares')->assertSee('Freelance');
        $this->get('/')->assertOk()->assertSee('+ Nueva categoría', false);

        // Eliminar: los ingresos quedan sin categoría
        $this->delete("/categorias/{$free->id}")->assertSessionHasNoErrors();
        $this->assertNull($h->transactions()->where('type', 'income')->latest('id')->first()->category_id);
    }

    public function test_subcategorias_de_ingreso(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);

        // El registro ya trae sugeridas: Salario › Quincena
        $salario = $h->categories()->where('name', 'Salario')->where('type', 'income')->whereNull('parent_id')->first();
        $quincena = $h->categories()->where('parent_id', $salario->id)->where('name', 'Quincena')->first();
        $this->assertNotNull($quincena);
        $this->assertSame('income', $quincena->type);
        $this->assertSame(0, $h->seedSubcategories('income'));

        // Crear una propia: hereda tipo ingreso y color
        $this->post('/categorias', ['name' => 'Comisiones', 'parent_id' => $salario->id])->assertSessionHasNoErrors();
        $com = $h->categories()->where('name', 'Comisiones')->first();
        $this->assertSame('income', $com->type);
        $this->assertSame($salario->color, $com->color);
        $this->assertSame('Salario › Comisiones', $com->fullName());

        // Registrar ingresos en la subcategoría y en la madre
        $this->post('/movimientos', ['type' => 'income', 'amount' => 400, 'currency' => 'USD', 'category_id' => $quincena->id, 'date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->post('/movimientos', ['type' => 'income', 'amount' => 100, 'currency' => 'USD', 'category_id' => $salario->id, 'date' => now()->toDateString()]);

        $sums = app(\App\Services\FinanceService::class)->incomeByCategory($h, now());
        $this->assertSame(500.0, $sums['rollup'][$salario->id]);
        $this->assertSame(400.0, $sums['direct'][$quincena->id]);

        // Un gasto no puede ir a una subcategoría de ingreso
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 5, 'currency' => 'USD', 'category_id' => $quincena->id, 'date' => now()->toDateString()]);

        // Pantallas
        $this->get('/')->assertOk()->assertSee('Quincena');                      // selector agrupado del modal
        $this->get('/presupuesto')->assertOk()->assertSee('Comisiones')->assertSee('Quincena');
        $this->get('/movimientos')->assertOk()->assertSee('Salario › Quincena');
        $this->get('/reportes')->assertOk()->assertSee('Ingresos por categoría')->assertSee('$500.00');
    }

    public function test_donde_o_para_quien_guarda_filtra_y_reporta(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $edu = $h->categories()->where('name', 'Educación')->whereNull('parent_id')->first();
        $gasto = fn ($n, $place) => $this->actingAs($u)->post('/movimientos', ['type' => 'expense', 'amount' => $n, 'currency' => 'USD',
            'category_id' => $edu->id, 'place' => $place, 'date' => now()->toDateString()]);

        $gasto(120, 'Colegio San José (María)')->assertSessionHasNoErrors();
        $gasto(45, 'Curso de inglés (Pedro)');
        $gasto(30, '  colegio san josé (maría)  ');   // misma persona/lugar, distinta escritura

        // Se reutiliza la escritura original
        $this->assertSame(2, $h->transactions()->where('place', 'Colegio San José (María)')->count());
        $this->assertSame(0, $h->transactions()->where('place', 'colegio san josé (maría)')->count());

        // Filtro y resumen en Movimientos
        $this->get('/movimientos')->assertOk()->assertSee('Por lugar')->assertSee('Curso de inglés (Pedro)');
        $html = $this->get('/movimientos?lugar='.urlencode('Colegio San José (María)'))->assertOk()->getContent();
        $this->assertStringContainsString('2 movimientos', $html);
        $this->assertStringNotContainsString('Sin movimientos todavía', $html);

        // Corregir el lugar de un movimiento existente
        $tx = $h->transactions()->where('place', 'Curso de inglés (Pedro)')->first();
        $this->patch("/movimientos/{$tx->id}", ['place' => 'Academia de idiomas (Pedro)'])->assertSessionHasNoErrors();
        $this->assertSame('Academia de idiomas (Pedro)', $tx->fresh()->place);

        // Quitarlo
        $this->patch("/movimientos/{$tx->id}", ['place' => ''])->assertSessionHasNoErrors();
        $this->assertNull($tx->fresh()->place);

        // Reporte
        $this->get('/reportes')->assertOk()->assertSee('¿Dónde se gastó?')->assertSee('Colegio San José (María)')->assertSee('$150.00');

        // Sugerencias disponibles en los formularios
        $this->get('/')->assertOk()->assertSee('id="all-places"', false)->assertSee('Colegio San José (María)');
    }

    public function test_personas_de_la_familia_para_asignar_gastos(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $edu = $h->categories()->where('name', 'Educación')->whereNull('parent_id')->first();

        // Agregar desde Familia, sin duplicados
        $this->post('/personas', ['name' => 'María', 'relation' => 'hijo'])->assertSessionHasNoErrors();
        $this->post('/personas', ['name' => 'maría'])->assertSessionHasErrors('name');
        $this->post('/personas', ['name' => 'Sofía', 'relation' => 'hermano'])->assertSessionHasNoErrors();
        $this->assertSame('Hermano/a', $h->people()->where('name', 'Sofía')->first()->relationLabel());
        $this->post('/personas', ['name' => 'X', 'relation' => 'inventada'])->assertSessionHasErrors('relation');
        $h->people()->where('name', 'Sofía')->delete();
        $maria = $h->people()->first();
        $this->assertSame('Hijo/a', $maria->relationLabel());

        // Gasto asignado a una persona, y otra creada sobre la marcha
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 200, 'currency' => 'USD', 'category_id' => $edu->id, 'person_id' => $maria->id, 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 80, 'currency' => 'USD', 'category_id' => $edu->id, 'new_person' => 'Pedro', 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $pedro = $h->people()->where('name', 'Pedro')->first();
        $this->assertNotNull($pedro);
        $this->assertSame($pedro->id, $h->transactions()->latest('id')->first()->person_id);

        // No se puede asignar a una persona de otra familia
        $otra = Household::create(['name' => 'Otra']);
        $ajena = $otra->people()->create(['name' => 'Ajena', 'color' => '#000000']);
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 5, 'currency' => 'USD', 'person_id' => $ajena->id, 'date' => now()->toDateString()])
            ->assertSessionHasErrors('person_id');

        // Un ingreso no guarda persona
        $this->post('/movimientos', ['type' => 'income', 'amount' => 10, 'currency' => 'USD', 'person_id' => $maria->id, 'date' => now()->toDateString()]);
        $this->assertNull($h->transactions()->where('type', 'income')->first()->person_id);

        // Filtro y totales en Movimientos
        $html = $this->get('/movimientos?tipo=expense&persona='.$maria->id)->assertOk()->assertSee('Por persona')->getContent();
        $this->assertStringContainsString('1 movimiento', $html);

        // Reasignar a otra persona
        $tx = $h->transactions()->where('person_id', $maria->id)->first();
        $this->patch("/movimientos/{$tx->id}", ['person_id' => $pedro->id])->assertSessionHasNoErrors();
        $this->assertSame($pedro->id, $tx->fresh()->person_id);

        // Gasto fijo con persona: el pago la hereda
        $fx = $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Colegio de María', 'amount' => 150, 'due_day' => 5, 'category_id' => $edu->id, 'person_id' => $maria->id]);
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => now()->format('Y-m')]);
        $this->assertSame($maria->id, $h->transactions()->where('fixed_expense_id', $fx->id)->first()->person_id);

        // Reporte y pantalla de Familia
        $this->get('/reportes')->assertOk()->assertSee('Gasto por persona')->assertSee('María')->assertSee('Pedro')->assertSee('Educación');
        $this->get('/familia')->assertOk()->assertSee('Personas de la familia')->assertSee('María');
        $csv = $this->get('/reportes/exportar')->streamedContent();
        $this->assertStringContainsString('Persona', $csv);

        // Eliminar: los gastos se conservan sin persona
        $this->delete("/personas/{$pedro->id}")->assertSessionHasNoErrors();
        $this->assertNull($tx->fresh()->person_id);
        $this->assertNotNull($tx->fresh());
    }

    public function test_lista_vacia_de_personas_invita_a_crearlas_en_el_formulario(): void
    {
        $u = $this->owner();

        // Sin personas: el formulario explica y muestra el campo de nombre
        $this->actingAs($u)->get('/')->assertOk()
            ->assertSee('Aún no tienes personas en tu lista')
            ->assertDontSee('Toda la familia');

        // Al crearla desde el gasto, la próxima vez ya aparece como opción
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 25, 'currency' => 'USD', 'new_person' => 'Luna', 'date' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee('Toda la familia')->assertSee('Luna')->assertDontSee('Aún no tienes personas en tu lista');
    }

    public function test_gasto_compartido_con_porcentaje_y_seguimiento_de_pago(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $edu = $h->categories()->where('name', 'Educación')->whereNull('parent_id')->first();
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899']);
        $luis = $h->people()->create(['name' => 'Luis', 'relation' => 'otro', 'color' => '#10b981']);

        $this->post('/movimientos', ['type' => 'expense', 'amount' => 1000, 'currency' => 'USD', 'category_id' => $edu->id, 'person_id' => $ana->id,
            'note' => 'Universidad de Ana', 'date' => now()->toDateString()])->assertSessionHasNoErrors();
        $tx = $h->transactions()->first();

        // Compartir: Ana 40 %, Luis 10 %; el resto (50 %) queda a cargo de quien lo registró
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 40, $luis->id => 10]])->assertSessionHasNoErrors();
        $d = $h->debts()->where('person_id', $ana->id)->first();
        $this->assertSame('shared', $d->kind);          // por defecto: cada quien paga su parte
        $this->assertSame('400.00', (string) $d->amount);
        // Tu gasto real es solo tu parte (50 %); el total queda como referencia
        $this->assertSame('500.00', (string) $tx->fresh()->amount);
        $this->assertSame('1000.00', (string) $tx->fresh()->total_amount);
        $this->assertSame('40.00', (string) $d->percent);
        $this->assertSame($tx->id, $d->transaction_id);
        $this->assertSame('100.00', (string) $h->debts()->where('person_id', $luis->id)->first()->amount);
        $this->assertSame('pending', $d->shareStatus());

        // Aparece en Movimientos (pendiente) y en Deudas como "Me deben"
        $this->get('/movimientos')->assertOk()->assertSee('Ana 40 %')->assertSee('Pendiente');
        $this->get('/deudas')->assertOk()->assertSee('40 % del gasto');

        // Pago parcial y total (seguimiento)
        $this->post("/deudas/{$d->id}/pagos", ['amount' => 150, 'currency' => 'USD']);
        $this->assertSame('partial', $d->fresh()->shareStatus());
        $this->get('/movimientos')->assertSee('Pagó una parte')->assertSee('falta $250.00');
        $this->post("/deudas/{$d->id}/pagos", ['amount' => 250, 'currency' => 'USD']);
        $this->assertSame('paid', $d->fresh()->shareStatus());
        $this->get('/movimientos')->assertSee('Pagó');

        // Más de 100 % se rechaza
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 70, $luis->id => 40]])->assertSessionHasErrors('shares');

        // Persona de otra familia: rechazada
        $otra = Household::create(['name' => 'Otra']);
        $ajena = $otra->people()->create(['name' => 'Ajena', 'color' => '#000000']);
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ajena->id => 10]])->assertSessionHasErrors('shares');

        // Editar: cambiar a Ana al 30 % y quitar a Luis (sin pagos → se elimina)
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 30]])->assertSessionHasNoErrors();
        $this->assertNull($h->debts()->where('person_id', $luis->id)->first());
        // Ana ya pagó 400: no puede quedar debiendo menos de lo pagado
        $this->assertSame('400.00', (string) $d->fresh()->amount);

        // Un ingreso no se puede compartir
        $inc = $h->transactions()->create(['user_id' => $u->id, 'type' => 'income', 'amount' => 10, 'original_amount' => 10, 'currency' => 'USD', 'date' => now()]);
        $this->post("/movimientos/{$inc->id}/compartir", ['shares' => [$ana->id => 10]])->assertStatus(422);

        // Otra familia no puede tocar este gasto
        $b = User::create(['name' => 'Bob', 'email' => 'bob3@example.test', 'password' => 'password-123', 'household_id' => $otra->id, 'role' => 'owner']);
        $this->actingAs($b)->post("/movimientos/{$tx->id}/compartir", ['shares' => []])->assertNotFound();
    }

    public function test_compartir_al_registrar_y_borrar_el_gasto_limpia_partes_sin_pagos(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $ana = $h->people()->create(['name' => 'Ana', 'color' => '#ec4899']);

        $this->post('/movimientos', ['type' => 'expense', 'amount' => 200, 'currency' => 'USD', 'share_person_id' => $ana->id, 'share_percent' => 25,
            'date' => now()->toDateString()])->assertSessionHasNoErrors();
        $tx = $h->transactions()->first();
        $this->assertSame('50.00', (string) $h->debts()->first()->amount);

        // El formulario ofrece la opción
        $this->get('/')->assertOk()->assertSee('¿Alguien más paga parte?');

        // Borrar el gasto sin pagos elimina la parte; con pagos la conserva (ya sin ligar al gasto)
        $this->delete("/movimientos/{$tx->id}");
        $this->assertSame(0, $h->debts()->count());

        $this->post('/movimientos', ['type' => 'expense', 'amount' => 100, 'currency' => 'USD', 'share_person_id' => $ana->id, 'share_percent' => 50, 'date' => now()->toDateString()]);
        $tx2 = $h->transactions()->first();
        $debt = $h->debts()->first();
        $this->post("/deudas/{$debt->id}/pagos", ['amount' => 20, 'currency' => 'USD']);
        $this->delete("/movimientos/{$tx2->id}");
        $this->assertSame(1, $h->debts()->count());
        $this->assertNull($debt->fresh()->transaction_id);
    }

    public function test_persona_con_cuenta_propia_solo_ve_los_gastos_compartidos(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $h = $owner->household;
        $edu = $h->categories()->where('name', 'Educación')->whereNull('parent_id')->first();
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899']);

        // 1) El titular invita a Ana a tener cuenta (con correo)
        $this->actingAs($owner)->post("/personas/{$ana->id}/invitar", ['email' => 'ana.perez@example.test'])->assertSessionHas('link');
        Notification::assertSentOnDemand(ParticipantInvitationNotification::class);
        $inv = $h->invitations()->whereNotNull('person_id')->first();
        $this->assertTrue($inv->isParticipant());
        // la invitación de participante no aparece como invitación de familia
        $this->get('/familia')->assertOk()->assertSee('Invitación pendiente');

        // 2) Ana se registra con ese enlace: cuenta propia, NO se une a la familia del titular
        auth()->logout();
        $this->get('/registro/'.$inv->token)->assertOk()->assertSee('propia cuenta');
        $this->post('/registro', ['name' => 'Ana Pérez', 'email' => 'ana.perez@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'token' => $inv->token])
            ->assertRedirect('/compartidos');
        $anaUser = User::where('email', 'ana.perez@example.test')->first();
        $this->assertNotSame($h->id, $anaUser->household_id);
        $this->assertSame('owner', $anaUser->role);
        $this->assertSame($anaUser->id, $ana->fresh()->user_id);

        // 3) El titular registra un gasto y comparte el 40 % con Ana (aviso por correo a Ana)
        auth()->logout();
        $this->actingAs($owner);
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 1000, 'currency' => 'USD', 'category_id' => $edu->id, 'note' => 'Universidad de Ana', 'date' => now()->toDateString()]);
        $privado = $h->transactions()->create(['user_id' => $owner->id, 'type' => 'expense', 'amount' => 77, 'original_amount' => 77, 'currency' => 'USD', 'date' => now(), 'note' => 'Gasto privado del titular']);
        $tx = $h->transactions()->where('note', 'Universidad de Ana')->first();
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 40]])->assertSessionHasNoErrors();
        Notification::assertSentTo($anaUser, ShareReceivedNotification::class);
        $debt = $h->debts()->first();

        // 4) Ana ve SOLO lo compartido
        auth()->logout();
        $this->actingAs($anaUser->fresh());
        $this->get('/compartidos')->assertOk()
            ->assertSee('Universidad de Ana')->assertSee('40 %')->assertSee('$400.00')
            ->assertDontSee('Gasto privado del titular');
        $this->get('/movimientos')->assertOk()->assertDontSee('Universidad de Ana')->assertDontSee('Gasto privado del titular');
        $this->get('/deudas')->assertOk()->assertDontSee('Universidad de Ana');
        $this->get('/')->assertOk()->assertSee('Gastos compartidos contigo')->assertSee('$400.00');

        // 5) Ana reporta un pago parcial; el titular recibe aviso y lo ve en Deudas
        $this->post("/compartidos/{$debt->id}/pagar", ['amount' => 150, 'note' => 'Transferencia'])->assertSessionHasNoErrors();
        $this->assertSame('partial', $debt->fresh()->shareStatus());
        Notification::assertSentTo($owner, SharedPaymentReportedNotification::class);
        $this->assertSame($anaUser->id, $debt->payments()->first()->reported_by);
        // no puede pagar de más: se limita a lo que falta
        $this->post("/compartidos/{$debt->id}/pagar", ['amount' => 9999]);
        $this->assertSame('paid', $debt->fresh()->shareStatus());
        $this->assertEquals(400.0, (float) $debt->fresh()->payments()->sum('amount'));

        // 6) El titular ve quién reportó y puede deshacerlo
        auth()->logout();
        $this->actingAs($owner);
        $this->get('/deudas')->assertOk()->assertSee('reportado por Ana Pérez')->assertSee('con cuenta');
        $last = $debt->payments()->first();
        $this->delete("/deudas/{$debt->id}/pagos/{$last->id}")->assertSessionHasNoErrors();
        $this->assertSame('partial', $debt->fresh()->shareStatus());

        // 7) Ana no puede tocar deudas ajenas ni de otras personas
        $otraPersona = $h->people()->create(['name' => 'Luis', 'color' => '#10b981']);
        $otra = $h->debts()->create(['user_id' => $owner->id, 'kind' => 'lent', 'person' => 'Luis', 'person_id' => $otraPersona->id, 'amount' => 50]);
        auth()->logout();
        $this->actingAs($anaUser->fresh());
        $this->post("/compartidos/{$otra->id}/pagar", ['amount' => 10])->assertNotFound();

        // 8) El titular desvincula: Ana ya no ve nada
        auth()->logout();
        $this->actingAs($owner);
        $this->delete("/personas/{$ana->id}/vinculo")->assertSessionHasNoErrors();
        auth()->logout();
        $this->actingAs($anaUser->fresh());
        $this->get('/compartidos')->assertOk()->assertDontSee('Universidad de Ana')->assertSee('Aún no te han compartido gastos');
    }

    public function test_usuario_existente_acepta_ver_gastos_compartidos(): void
    {
        $owner = $this->owner();
        $h = $owner->household;
        $ana = $h->people()->create(['name' => 'Ana', 'color' => '#ec4899']);
        $this->actingAs($owner);
        $inv = \App\Models\Invitation::issueForPerson($ana, $owner);

        // Cuenta existente con su propia familia
        $otro = Household::create(['name' => 'Familia Ana']);
        $anaUser = User::create(['name' => 'Ana R', 'email' => 'anar@example.test', 'password' => 'password-123', 'household_id' => $otro->id, 'role' => 'owner']);

        // Invitado no autenticado: va al login
        auth()->logout();
        $this->get('/compartido/'.$inv->token)->assertRedirect('/entrar');

        $this->actingAs($anaUser)->get('/compartido/'.$inv->token)->assertRedirect('/compartidos');
        $this->assertSame($anaUser->id, $ana->fresh()->user_id);
        $this->assertNotNull($inv->fresh()->accepted_at);
        $this->get('/compartidos')->assertOk()->assertSee('Compartidos');

        // El enlace es de un solo uso; y un miembro de la misma familia no lo necesita
        $this->actingAs($owner)->get('/compartido/'.$inv->token)->assertRedirect('/');
        $inv2 = \App\Models\Invitation::issueForPerson($h->people()->create(['name' => 'Beto', 'color' => '#000']), $owner);
        $this->actingAs($owner)->get('/compartido/'.$inv2->token)->assertRedirect('/');
        $this->assertNull($inv2->person->fresh()->user_id);
    }

    public function test_gasto_fijo_cuatrimestral_aparece_solo_en_sus_meses_con_numero_de_cuota(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $svc = app(\App\Services\FixedExpenseService::class);
        $inicio = now()->startOfMonth()->subMonths(4)->day(10);

        $this->actingAs($u)->post('/gastos-fijos', [
            'name' => 'Universidad', 'amount' => 1200, 'frequency' => 'cuatrimestral',
            'start_date' => $inicio->toDateString(), 'installments' => 3,
        ])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();
        $this->assertSame('Cuatrimestral', $fx->frequencyLabel());
        $this->assertSame(4, $fx->interval_count);

        // Vence hace 4 meses (cuota 1), este mes (cuota 2) y dentro de 4 (cuota 3); en los demás, nada
        $this->assertSame(1, $svc->month($h, $inicio)->count());
        $this->assertSame(0, $svc->month($h, now()->startOfMonth()->subMonths(2))->count());
        $esteMes = $svc->month($h, now())->first();
        $this->assertSame(2, $esteMes['n']);
        $this->assertSame(1, $svc->month($h, now()->startOfMonth()->addMonths(4))->count());
        $this->assertSame(0, $svc->month($h, now()->startOfMonth()->addMonths(8))->count());   // solo 3 cuotas

        $this->get('/gastos-fijos')->assertOk()->assertSee('cuota 2 de 3')->assertSee('Cuatrimestral');

        // Pagar la cuota de este mes por su vencimiento exacto; la de hace 4 meses sigue pendiente
        $due = $esteMes['key'];
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['due' => $due])->assertSessionHasNoErrors();
        $this->assertSame('paid', $svc->month($h, now())->first()['status']);
        $this->assertSame('overdue', $svc->month($h, $inicio)->first()['status']);
        $this->assertSame($due, $h->transactions()->first()->fixed_due->toDateString());

        // Un vencimiento que no es de este gasto se rechaza
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['due' => now()->startOfMonth()->subMonths(2)->toDateString()])->assertStatus(422);
        $this->delete("/gastos-fijos/{$fx->id}/pagos/{$due}");
        $this->assertNotSame('paid', $svc->month($h, now())->first()['status']);
        $this->assertSame(0, $h->transactions()->count());
    }

    public function test_gasto_fijo_cada_21_dias_genera_varias_cuotas_en_un_mes_y_se_paga_cada_una(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $svc = app(\App\Services\FixedExpenseService::class);
        $inicio = now()->startOfMonth();

        $this->actingAs($u)->post('/gastos-fijos', [
            'name' => 'Cuotas de la universidad', 'amount' => 300, 'frequency' => 'days', 'interval_days' => 21,
            'start_date' => $inicio->toDateString(), 'installments' => 6,
        ])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();
        $this->assertSame('Cada 21 días', $fx->frequencyLabel());

        // Cuotas: día 1 y día 22 de este mes (la 3.ª cae el día 43 → mes siguiente)
        $rows = $svc->month($h, now());
        $this->assertSame([1, 2], $rows->pluck('n')->all());
        $this->assertSame($inicio->copy()->addDays(21)->toDateString(), $rows[1]['key']);

        // Pagar solo la primera no paga la segunda
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['due' => $rows[0]['key']])->assertSessionHasNoErrors();
        $after = $svc->month($h, now());
        $this->assertSame('paid', $after[0]['status']);
        $this->assertNotSame('paid', $after[1]['status']);
        $totals = $svc->totals($after);
        $this->assertSame(1, $totals['paid_count']);
        $this->assertSame(2, $totals['count']);

        // La cuota siguiente cae el día 43 (mes que viene o el siguiente)
        $this->assertSame(3, $svc->month($h, $inicio->copy()->addDays(42))->first()['n']);
        // Tope de 6 cuotas
        $this->assertSame(0, $svc->month($h, $inicio->copy()->addDays(21 * 6 + 40))->count());
    }

    public function test_validaciones_de_frecuencia(): void
    {
        $u = $this->owner();
        $this->actingAs($u);

        $this->post('/gastos-fijos', ['name' => 'A', 'amount' => 10, 'frequency' => 'monthly'])->assertSessionHasErrors('due_day');
        $this->post('/gastos-fijos', ['name' => 'B', 'amount' => 10, 'frequency' => 'quarterly'])->assertSessionHasErrors('start_date');
        $this->post('/gastos-fijos', ['name' => 'C', 'amount' => 10, 'frequency' => 'days', 'start_date' => now()->toDateString()])->assertSessionHasErrors('interval_days');
        $ana = $u->household->people()->create(['name' => 'Ana', 'color' => '#ec4899']);
        $this->post('/gastos-fijos', ['name' => 'D', 'amount' => 10, 'frequency' => 'monthly', 'due_day' => 5, 'share_person_id' => $ana->id])->assertSessionHasErrors('share_percent');
        $this->post('/gastos-fijos', ['name' => 'E', 'amount' => 10, 'frequency' => 'inventada', 'due_day' => 5])->assertSessionHasErrors('frequency');
        $this->assertSame(0, $u->household->fixedExpenses()->count());

        // Mensual de siempre sigue funcionando
        $this->post('/gastos-fijos', ['name' => 'Luz', 'amount' => 30, 'frequency' => 'monthly', 'due_day' => 12])->assertSessionHasNoErrors();
        $this->assertTrue($u->household->fixedExpenses()->first()->isMonthly());
    }

    public function test_gasto_fijo_compartido_a_futuro_avisa_al_crearlo_y_al_cambiar_la_parte(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $cuenta = User::create(['name' => 'Emely', 'email' => 'emely@example.test', 'password' => 'password-123',
            'household_id' => Household::create(['name' => 'Fam Emely'])->id, 'role' => 'owner']);
        $em = $h->people()->create(['name' => 'Emely', 'relation' => 'hermano', 'color' => '#ec4899', 'user_id' => $cuenta->id]);

        // Fecha de inicio a futuro (lejos de los próximos 45 días): igual debe avisarle
        $this->post('/gastos-fijos', [
            'name' => 'Universidad', 'amount' => 900, 'frequency' => 'cuatrimestral', 'start_date' => now()->addMonths(4)->toDateString(),
            'share_person_id' => $em->id, 'share_percent' => 33,
        ])->assertSessionHasNoErrors();
        Notification::assertSentToTimes($cuenta, \App\Notifications\FixedShareNotification::class, 1);

        // Editar sin cambiar la parte no repite el aviso; cambiar el porcentaje sí
        $fx = $h->fixedExpenses()->first();
        $base = ['name' => 'Universidad', 'amount' => 900, 'frequency' => 'cuatrimestral', 'start_date' => now()->addMonths(4)->toDateString(), 'share_person_id' => $em->id];
        $this->patch("/gastos-fijos/{$fx->id}", $base + ['share_percent' => 33])->assertSessionHasNoErrors();
        Notification::assertSentToTimes($cuenta, \App\Notifications\FixedShareNotification::class, 1);
        $this->patch("/gastos-fijos/{$fx->id}", $base + ['share_percent' => 50])->assertSessionHasNoErrors();
        Notification::assertSentToTimes($cuenta, \App\Notifications\FixedShareNotification::class, 2);
    }

    public function test_el_titular_ve_las_cuotas_programadas_de_un_gasto_fijo_compartido(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899']);

        $this->post('/gastos-fijos', [
            'name' => 'Colegio', 'amount' => 300, 'frequency' => 'monthly', 'due_day' => now()->addDays(10)->day,
            'share_person_id' => $ana->id, 'share_percent' => 50,
        ])->assertSessionHasNoErrors();

        $this->get('/deudas')->assertOk()->assertSee('Cuotas programadas de gastos fijos compartidos')->assertSee('Gasto compartido: Colegio');
    }

    public function test_gasto_fijo_compartido_lejano_programa_igual_la_proxima_cuota(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899']);

        $this->post('/gastos-fijos', [
            'name' => 'Universidad', 'amount' => 356, 'frequency' => 'cuatrimestral', 'start_date' => now()->addDays(60)->toDateString(),
            'share_person_id' => $ana->id, 'share_percent' => 28,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $h->debts()->count());
        $this->get('/deudas')->assertOk()->assertSee('Gasto compartido: Universidad');
    }

    public function test_gasto_fijo_compartido_crea_la_parte_en_cada_pago_y_avisa_a_la_persona(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $cuenta = User::create(['name' => 'Ana Hermana', 'email' => 'hermana@example.test', 'password' => 'password-123',
            'household_id' => Household::create(['name' => 'Fam Ana'])->id, 'role' => 'owner']);
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899', 'user_id' => $cuenta->id]);

        $this->post('/gastos-fijos', [
            'name' => 'Universidad de Ana', 'amount' => 1000, 'frequency' => 'cuatrimestral', 'start_date' => now()->startOfMonth()->toDateString(),
            'share_person_id' => $ana->id, 'share_percent' => 40,
        ])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();
        $this->assertSame($ana->id, $fx->share_person_id);

        $this->post("/gastos-fijos/{$fx->id}/pagar", ['due' => now()->startOfMonth()->toDateString()])->assertSessionHasNoErrors();

        $debt = $h->debts()->whereDate('fixed_due', now()->startOfMonth()->toDateString())->first();
        $this->assertSame('400.00', (string) $debt->amount);
        $this->assertSame($ana->id, $debt->person_id);
        $this->assertSame($h->transactions()->first()->id, $debt->transaction_id);
        Notification::assertSentTo($cuenta, ShareReceivedNotification::class);

        // Deshacer el pago: la cuota queda programada otra vez (sin gasto ligado), no se pierde el calendario
        $this->delete("/gastos-fijos/{$fx->id}/pagos/".now()->startOfMonth()->toDateString());
        $this->assertSame(0, $h->transactions()->count());
        $this->assertNull($h->debts()->where('fixed_due', now()->startOfMonth()->toDateString())->first()?->transaction_id);
        $this->assertSame(0, $h->debts()->incurred()->whereNotNull('transaction_id')->count());
    }

    public function test_recordatorio_de_vencimiento_para_quien_debe_su_parte(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $cuenta = User::create(['name' => 'Ana Hermana', 'email' => 'hermana2@example.test', 'password' => 'password-123',
            'household_id' => Household::create(['name' => 'Fam Ana'])->id, 'role' => 'owner']);
        $ana = $h->people()->create(['name' => 'Ana', 'color' => '#ec4899', 'user_id' => $cuenta->id]);
        $sinCuenta = $h->people()->create(['name' => 'Luis', 'color' => '#10b981']);

        $h->debts()->create(['user_id' => $u->id, 'kind' => 'lent', 'person' => 'Ana', 'person_id' => $ana->id, 'amount' => 400, 'percent' => 40, 'due_date' => today()->addDays(3), 'note' => 'Gasto compartido: Universidad']);
        $h->debts()->create(['user_id' => $u->id, 'kind' => 'lent', 'person' => 'Luis', 'person_id' => $sinCuenta->id, 'amount' => 50, 'due_date' => today()->addDays(3)]);

        $this->artisan('notify:debts')->assertSuccessful();
        Notification::assertSentTo($cuenta, SharedDueReminderNotification::class, function ($n) use ($cuenta) {
            $html = (string) $n->toMail($cuenta)->render();
            return str_contains($html, 'Universidad') && str_contains($html, '$400.00') && ! str_contains($html, 'Luis');
        });
        Notification::assertSentToTimes($cuenta, SharedDueReminderNotification::class, 1);

        // No se repite el mismo día
        $this->artisan('notify:debts')->assertSuccessful();
        Notification::assertSentToTimes($cuenta, SharedDueReminderNotification::class, 1);

        // Respeta la preferencia de avisos
        Notification::fake();
        \Illuminate\Support\Facades\Cache::flush();
        $cuenta->update(['notify_email' => false]);
        $this->artisan('notify:debts')->assertSuccessful();
        Notification::assertNotSentTo($cuenta, SharedDueReminderNotification::class);
    }

    public function test_periodo_de_4_meses_con_cuotas_cada_21_dias_que_se_repite(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $inicio = now()->startOfMonth();

        $this->post('/gastos-fijos', [
            'name' => 'Universidad', 'amount' => 250, 'frequency' => 'days', 'interval_days' => 21,
            'start_date' => $inicio->toDateString(), 'duration_months' => 4, 'repeat_term' => 1,
        ])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();
        $this->assertSame(4, $fx->duration_months);
        $this->assertSame(4, $fx->repeat_months);
        $this->assertStringContainsString('durante 4 meses', $fx->termLabel());

        // Primer periodo: cuotas día 0, 21, 42… mientras sea anterior a inicio + 4 meses
        $fin1 = $inicio->copy()->addMonthsNoOverflow(4);
        $occ1 = $fx->occurrencesBetween($inicio, $fin1->copy()->subDay());
        $esperadas = (int) floor($inicio->diffInDays($fin1, true) - 0.0001 > 0 ? ($inicio->diffInDays($fin1, true) - 1) / 21 : 0) + 1;
        $this->assertSame($esperadas, $occ1->count());
        $this->assertSame(range(1, $esperadas), $occ1->pluck('n')->all());
        $this->assertSame($esperadas, $occ1->first()['of']);
        $this->assertTrue($occ1->every(fn ($o) => $o['due']->lt($fin1)));

        // El periodo se repite: el segundo empieza justo al cumplirse los 4 meses (cuota 1 de nuevo)
        $segundo = $fx->occurrencesBetween($fin1, $fin1->copy()->addDays(5));
        $this->assertSame(1, $segundo->count());
        $this->assertSame($fin1->toDateString(), $segundo->first()['due']->toDateString());
        $this->assertSame(1, $segundo->first()['n']);

        // Sin repetir: después del periodo no hay más cuotas
        $fx->update(['repeat_months' => null]);
        $this->assertSame(0, $fx->fresh()->occurrencesBetween($fin1, $fin1->copy()->addMonths(8))->count());

        // Repetir sin duración es un error
        $this->post('/gastos-fijos', ['name' => 'X', 'amount' => 5, 'frequency' => 'days', 'interval_days' => 7, 'start_date' => $inicio->toDateString(), 'repeat_term' => 1])
            ->assertSessionHasErrors('duration_months');
    }

    public function test_cuotas_compartidas_aparecen_por_adelantado_a_la_persona_con_cuenta(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $cuenta = User::create(['name' => 'Ana Hermana', 'email' => 'hermana3@example.test', 'password' => 'password-123',
            'household_id' => Household::create(['name' => 'Fam Ana'])->id, 'role' => 'owner']);
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899', 'user_id' => $cuenta->id]);

        $this->post('/gastos-fijos', [
            'name' => 'Universidad de Ana', 'amount' => 500, 'frequency' => 'days', 'interval_days' => 21,
            'start_date' => today()->toDateString(), 'duration_months' => 4, 'share_person_id' => $ana->id, 'share_percent' => 40,
        ])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();

        // Cuotas de los próximos 45 días ya programadas para Ana: hoy, +21 y +42
        $prog = $h->debts()->where('fixed_expense_id', $fx->id)->orderBy('fixed_due')->get();
        $this->assertSame(3, $prog->count());
        $this->assertSame('200.00', (string) $prog[0]->amount);
        $this->assertNull($prog[0]->transaction_id);
        $this->assertSame(today()->addDays(21)->toDateString(), $prog[1]->fixed_due->toDateString());

        // Sincronizar de nuevo no duplica
        $this->artisan('fixed:sync-shares')->assertSuccessful();
        $this->assertSame(3, $h->debts()->where('fixed_expense_id', $fx->id)->count());

        // El titular las ve aparte como "programadas" (no cuentan como "me deben" hasta pagar la cuota)
        $this->get('/deudas')->assertOk()->assertSee('Cuotas programadas de gastos fijos compartidos')->assertSee('Universidad de Ana');

        // Ana sí ve su calendario como gasto fijo
        auth()->logout();
        $this->actingAs($cuenta->fresh());
        $this->get('/compartidos')->assertOk()
            ->assertSee('Tus gastos fijos compartidos')->assertSee('Universidad de Ana')->assertSee('Cada 21 días')->assertSee('durante 4 meses')
            ->assertSee('$200.00')->assertSee('Gasto fijo');

        // Puede reportar un pago adelantado de una cuota programada
        $this->post("/compartidos/{$prog[0]->id}/pagar", ['amount' => 200])->assertSessionHasNoErrors();
        $this->assertSame('paid', $prog[0]->fresh()->shareStatus());

        // Cuando el titular paga esa cuota, se enlaza (no se duplica) y ajusta al monto real
        auth()->logout();
        $this->actingAs($u);
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['due' => today()->toDateString(), 'amount' => 550])->assertSessionHasNoErrors();
        $this->assertSame(3, $h->debts()->where('fixed_expense_id', $fx->id)->count());
        $enlazada = $prog[0]->fresh();
        $this->assertSame($h->transactions()->first()->id, $enlazada->transaction_id);
        $this->assertSame('220.00', (string) $enlazada->amount);          // 40 % de 550
        $this->get('/deudas')->assertOk()->assertSee('Universidad');          // ya incurrida

        // Pausar el gasto retira las partes programadas sin pagos (la ya pagada se conserva)
        $this->patch("/gastos-fijos/{$fx->id}", ['toggle' => 1]);
        $this->assertSame(1, $h->debts()->where('fixed_expense_id', $fx->id)->count());
    }

    public function test_pago_repartido_registra_solo_tu_parte_y_se_completa_cuando_los_demas_pagan(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $svc = app(\App\Services\FixedExpenseService::class);
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899']);

        $this->post('/gastos-fijos', [
            'name' => 'Universidad', 'amount' => 1000, 'frequency' => 'monthly', 'due_day' => 5,
            'share_person_id' => $ana->id, 'share_percent' => 40, 'share_mode' => 'split',
        ])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();
        $mes = now()->format('Y-m');

        // Antes de pagar: lo que te toca es tu 60 %, no el total
        $fila = $svc->month($h, now())->first();
        $this->assertSame(600.0, $fila['my']);
        $this->assertSame(600.0, $svc->totals($svc->month($h, now()))['pending']);
        $this->get('/gastos-fijos')->assertOk()->assertSee('a ti te toca')->assertSee('$600.00')->assertSee('Marcar mi parte pagada');

        // Marcar pagado: se registra solo tu parte; la cuota queda "esperando" a Ana
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => $mes])->assertSessionHasNoErrors();
        $tx = $h->transactions()->first();
        $this->assertSame('600.00', (string) $tx->amount);
        $this->assertSame('1000.00', (string) $tx->total_amount);
        $debt = $h->debts()->where('transaction_id', $tx->id)->first();
        $this->assertNotNull($debt);
        $this->assertSame('shared', $debt->kind);
        $this->assertSame('400.00', (string) $debt->amount);

        $fila = $svc->month($h, now())->first();
        $this->assertSame('paid', $fila['status']);            // tu parte
        $this->assertFalse($fila['complete']);                 // faltan los demás
        $this->assertSame(['Ana'], $fila['waiting']->all());
        $this->assertSame(1, $svc->totals($svc->month($h, now()))['awaiting_count']);
        $this->get('/gastos-fijos')->assertOk()->assertSee('Tu parte pagada')->assertSee('Falta que paguen su parte: Ana');

        // Los reportes y el balance usan tu parte, no el total
        $this->get('/reportes')->assertOk()->assertSee('$600.00')->assertDontSee('$1,000.00');
        // No es una deuda "me deben" tuya: aparece en la sección de pagos compartidos
        $this->get('/deudas')->assertOk()->assertSee('Pagos compartidos')->assertSee('40 % del gasto');
        $this->get('/')->assertOk();

        // Ana paga su parte → la cuota queda completada
        $this->post("/deudas/{$debt->id}/pagos", ['amount' => 400, 'currency' => 'USD'])->assertSessionHasNoErrors();
        $fila = $svc->month($h, now())->first();
        $this->assertTrue($fila['complete']);
        $this->assertTrue($fila['waiting']->isEmpty());
        $this->get('/gastos-fijos')->assertOk()->assertSee('Completado')->assertSee('Todos pagaron su parte');
    }

    public function test_modo_reembolso_registra_el_total_y_el_reparto_se_puede_cambiar(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $ana = $h->people()->create(['name' => 'Ana', 'color' => '#ec4899']);

        // Fijo con reembolso: pagas el total y te reembolsan
        $this->post('/gastos-fijos', ['name' => 'Cena familiar', 'amount' => 200, 'frequency' => 'monthly', 'due_day' => 3,
            'share_person_id' => $ana->id, 'share_percent' => 25, 'share_mode' => 'reimburse'])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->first();
        $this->assertFalse($fx->isSplitShared());
        $this->assertSame(200.0, $fx->myAmount());
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => now()->format('Y-m')]);
        $this->assertSame('200.00', (string) $h->transactions()->first()->amount);
        $this->assertNull($h->transactions()->first()->total_amount);
        $d = $h->debts()->where('transaction_id', $h->transactions()->first()->id)->first();
        $this->assertSame('lent', $d->kind);
        $this->assertSame('50.00', (string) $d->amount);

        // Gasto manual: pasar de reparto a reembolso devuelve tu gasto al total
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 1000, 'currency' => 'USD', 'date' => now()->toDateString(), 'note' => 'Matrícula']);
        $tx = $h->transactions()->where('note', 'Matrícula')->first();
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 40], 'mode' => 'split']);
        $this->assertSame('600.00', (string) $tx->fresh()->amount);
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 40], 'mode' => 'reimburse']);
        $this->assertSame('1000.00', (string) $tx->fresh()->amount);
        $this->assertNull($tx->fresh()->total_amount);
        $this->assertSame('lent', $h->debts()->where('transaction_id', $tx->id)->first()->kind);

        // Dejar de compartir restaura el total
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 40], 'mode' => 'split']);
        $this->assertSame('600.00', (string) $tx->fresh()->amount);
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => []]);
        $this->assertSame('1000.00', (string) $tx->fresh()->amount);

        // Al registrar con "alguien más paga parte" en modo reparto
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 400, 'currency' => 'USD', 'date' => now()->toDateString(), 'note' => 'Taller',
            'share_person_id' => $ana->id, 'share_percent' => 50, 'share_mode' => 'split']);
        $tx2 = $h->transactions()->where('note', 'Taller')->first();
        $this->assertSame('200.00', (string) $tx2->amount);
        $this->assertSame('400.00', (string) $tx2->total_amount);
        $this->assertSame('200.00', (string) $h->debts()->where('transaction_id', $tx2->id)->first()->amount);
    }

    public function test_para_mi_en_gastos_fijos_y_movimientos(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $ana = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899']);

        // "Para mí" aparece como primera opción, antes que el resto de personas
        $this->get('/gastos-fijos')->assertOk();   // no falla aunque aún no exista la persona "yo"
        $me = $h->mePerson($u);
        $this->assertSame($u->id, $me->member_user_id);
        $this->assertSame($me->id, $h->mePerson($u)->id);   // idempotente
        $html = $this->get('/gastos-fijos')->assertOk()->assertSee('Para mí')->getContent();
        $this->assertNotFalse(strpos($html, '>Para mí</option>'));
        $this->assertLessThan(strpos($html, '>Ana</option>'), strpos($html, '>Para mí</option>'));

        // Gasto fijo "para mí"
        $this->post('/gastos-fijos', ['name' => 'Gimnasio', 'amount' => 40, 'frequency' => 'monthly', 'due_day' => 3, 'person_id' => 'me'])->assertSessionHasNoErrors();
        $this->assertSame($me->id, $h->fixedExpenses()->first()->person_id);
        $fx = $h->fixedExpenses()->first();
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => now()->format('Y-m')]);
        $this->assertSame($me->id, $h->transactions()->first()->person_id);   // el pago hereda "para mí"

        // Gasto manual "para mí"
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 15, 'currency' => 'USD', 'person_id' => 'me', 'date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame($me->id, $h->transactions()->latest('id')->first()->person_id);

        // Reasignar un gasto a "mí"
        $tx = $h->transactions()->create(['user_id' => $u->id, 'type' => 'expense', 'amount' => 9, 'original_amount' => 9, 'currency' => 'USD', 'date' => now(), 'person_id' => $ana->id]);
        $this->patch("/movimientos/{$tx->id}", ['person_id' => 'me'])->assertSessionHasNoErrors();
        $this->assertSame($me->id, $tx->fresh()->person_id);

        // No se puede "compartir" contigo mismo: la persona "yo" no aparece para repartir
        $this->get('/gastos-fijos')->assertDontSee('value="'.$me->id.'" @selected', false);
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$ana->id => 30]])->assertSessionHasNoErrors();

        // En Familia aparece sin opciones de cuenta, con su explicación
        $this->get('/familia')->assertOk()->assertSee('«Para mí»');
    }

    /** Historial de 3 meses completos: ingresos y gastos fijos conocidos (para probar el plan realista). */
    private function historial(User $u, float $ingreso, float $gasto): void
    {
        $h = $u->household;
        $ocio = $h->categories()->where('name', 'Ocio')->whereNull('parent_id')->first();
        foreach ([1, 2, 3] as $back) {
            $m = now()->subMonthsNoOverflow($back)->startOfMonth();
            $h->transactions()->create(['user_id' => $u->id, 'type' => 'income', 'amount' => $ingreso, 'original_amount' => $ingreso, 'currency' => 'USD', 'date' => $m->copy()->day(5)]);
            $h->transactions()->create(['user_id' => $u->id, 'type' => 'expense', 'category_id' => $ocio->id, 'amount' => $gasto, 'original_amount' => $gasto, 'currency' => 'USD', 'date' => $m->copy()->day(10)]);
        }
    }

    public function test_aporte_a_meta_queda_en_historial_movimientos_y_reduce_lo_que_te_queda(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $fin = app(\App\Services\FinanceService::class);

        $h->transactions()->create(['user_id' => $u->id, 'type' => 'income', 'amount' => 1000, 'original_amount' => 1000, 'currency' => 'USD', 'date' => now()]);
        $this->post('/metas', ['name' => 'Moto Bera 250cc', 'target_amount' => 3000, 'deadline' => now()->addYear()->toDateString()])->assertSessionHasNoErrors();
        $goal = $h->goals()->first();

        $this->post("/metas/{$goal->id}/aportes", ['amount' => 200, 'currency' => 'USD', 'date' => now()->toDateString(), 'note' => 'Primer abono'])->assertSessionHasNoErrors();

        // 1) Historial de la meta
        $c = $goal->contributions()->first();
        $this->assertSame('200.00', (string) $c->amount);
        $this->assertNotNull($c->transaction_id);
        $this->get('/metas')->assertOk()->assertSee('Aportes registrados')->assertSee('Primer abono')->assertSee('$200.00');

        // 2) Movimiento de ahorro visible en Movimientos (y en su filtro)
        $tx = $h->transactions()->where('type', 'saving')->first();
        $this->assertSame($goal->id, $tx->goal_id);
        $this->get('/movimientos')->assertOk()->assertSee('Ahorro · Moto Bera 250cc');
        $this->get('/movimientos?tipo=saving')->assertOk()->assertSee('Primer abono')->assertDontSee('Sin movimientos todavía');

        // 3) No es un gasto, pero sí reduce lo que te queda
        $m = $fin->month($h, now());
        $this->assertSame(0.0, $m['expense']);
        $this->assertSame(200.0, $m['saved']);
        $this->assertSame(800.0, $m['balance']);   // 1000 − 0 − 200
        $this->assertSame(1000.0, $m['free']);
        $this->get('/')->assertOk()->assertSee('apartado para metas');

        // 4) El ahorro NO cuenta como gasto al calcular la capacidad de ahorro
        $this->historial($u, 2000, 1500);
        $this->assertEquals(500.0, round($fin->averageMonthlySurplus($h), 2));

        // 5) Quitar el aporte desde la meta elimina también el movimiento
        $this->delete("/metas/{$goal->id}/aportes/{$c->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, $h->transactions()->where('type', 'saving')->count());
        $this->assertSame(0, $goal->contributions()->count());

        // 6) Quitar el movimiento desde Movimientos elimina el aporte de la meta
        $this->post("/metas/{$goal->id}/aportes", ['amount' => 50, 'currency' => 'USD', 'date' => now()->toDateString()]);
        $tx = $h->transactions()->where('type', 'saving')->first();
        $this->delete("/movimientos/{$tx->id}");
        $this->assertSame(0, $goal->contributions()->count());

        // 7) Eliminar la meta libera lo apartado
        $this->post("/metas/{$goal->id}/aportes", ['amount' => 70, 'currency' => 'USD', 'date' => now()->toDateString()]);
        $this->delete("/metas/{$goal->id}");
        $this->assertSame(0, $h->transactions()->where('type', 'saving')->count());
    }

    public function test_plan_realista_de_meta_con_datos_reales(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $this->historial($u, 2000, 1500);   // te sobran 500/mes → capacidad segura 400 (80 %)

        $moto = $h->goals()->create(['name' => 'Moto Bera 250cc', 'target_amount' => 3000, 'deadline' => now()->addYear()]);
        $moto->contributions()->create(['user_id' => $u->id, 'amount' => 200, 'date' => now()]);

        $advisor = app(\App\Services\GoalAdvisor::class);
        $r = $advisor->plans($h, $h->goals()->get());
        $ctx = $r['context'];

        $this->assertSame(3, $ctx['months']);
        $this->assertSame('alta', $ctx['confidence']);
        $this->assertEquals(2000.0, round($ctx['income_avg'], 2));
        $this->assertEquals(1500.0, round($ctx['expense_avg'], 2));
        $this->assertEquals(400.0, round($ctx['capacity'], 2));   // 500 × 0.8
        $this->assertEquals(100.0, round($ctx['buffer'], 2));

        $p = $r['plans'][$moto->id];
        $this->assertSame(2800.0, round($p['remaining'], 2));
        $this->assertSame(12, $p['months_left']);
        $this->assertEquals(233.33, round($p['required'], 2));
        $this->assertSame('ajustada', $p['status']);   // 233 de 400 (58 %)
        $this->assertEquals(12, $p['eta_months']);
        $this->assertNotEmpty($p['scenarios']);
        $this->assertNotEmpty($p['tips']);
        // nunca recomienda apartar más de lo que queda este mes (70 %)
        $this->assertLessThanOrEqual(max(0, $ctx['remaining_now']) * 0.7 + 0.01, $p['this_month']);

        // Meta ambiciosa: no es viable en 12 meses y se dice cuánto tardaría de verdad
        $carro = $h->goals()->create(['name' => 'Carro', 'target_amount' => 12000, 'deadline' => now()->addYear()]);
        $r = $advisor->plans($h, $h->goals()->get());
        $c = $r['plans'][$carro->id];
        $this->assertSame('no_viable', $c['status']);
        $this->assertGreaterThan($c['allocation'], $c['required']);
        $this->assertGreaterThan(12, $c['eta_months']);
        $this->assertGreaterThan(0, $c['gap']);
        $this->assertTrue($r['overcommitted']);
        // con las dos metas la capacidad se reparte: la suma asignada no supera la capacidad
        $this->assertLessThanOrEqual($ctx['capacity'] + 0.01, $r['plans'][$moto->id]['allocation'] + $c['allocation']);

        $html = $this->get('/metas')->assertOk()->getContent();
        $this->assertStringContainsString('Plazo poco realista', $html);
        $this->assertStringContainsString('Plan realista', $html);
        $this->assertStringContainsString('Cómo llegar antes', $html);
        $this->assertStringContainsString('colchón', mb_strtolower($html));
    }

    public function test_plan_realista_sin_capacidad_o_con_pocos_datos(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $this->historial($u, 1000, 1200);   // gastas más de lo que ingresas

        $g = $h->goals()->create(['name' => 'Viaje', 'target_amount' => 1200, 'deadline' => now()->addMonths(6)]);
        $advisor = app(\App\Services\GoalAdvisor::class);
        $r = $advisor->plans($h, $h->goals()->get());

        $this->assertSame('sin_capacidad', $r['plans'][$g->id]['status']);
        $this->assertSame(0.0, $r['context']['capacity']);
        $this->assertEquals(0.0, $r['plans'][$g->id]['this_month']);
        $this->get('/metas')->assertOk()->assertSee('gastas igual o más de lo que ingresas');

        // Sin datos: fiabilidad baja y aviso explícito de que son pocos datos
        $otra = Household::create(['name' => 'Nueva', 'country' => 'VE']);
        $ctx = app(\App\Services\GoalAdvisor::class)->context($otra);
        $this->assertSame('baja', $ctx['confidence']);
        $this->assertSame(0, $ctx['months']);
    }

    public function test_gasto_imprevisto_se_registra_por_pagar_y_se_confirma_despues(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);

        $this->post('/movimientos', ['type' => 'expense', 'amount' => 80, 'currency' => 'USD', 'date' => now()->toDateString(), 'note' => 'Plomero', 'status' => 'pending'])
            ->assertSessionHasNoErrors();

        // No cuenta en ningún total, pero se ve en "Por pagar"
        $this->assertSame(0, $h->transactions()->count());
        $tx = $h->transactions()->withoutGlobalScope('paid')->first();
        $this->assertSame('pending', $tx->status);
        $this->get('/movimientos')->assertOk()->assertSee('Por pagar')->assertSee('Plomero')->assertSee('Confirmar pago');
        $this->get('/')->assertOk();

        // Confirmar: pasa a pagado y cuenta
        $this->post("/movimientos/{$tx->id}/confirmar", ['date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(1, $h->transactions()->count());
        $this->assertSame('paid', $tx->fresh()->status);
        $this->post("/movimientos/{$tx->id}/confirmar")->assertStatus(422);

        // Por defecto (sin indicar estado) un gasto queda pagado; los ingresos nunca quedan por pagar
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 5, 'currency' => 'USD', 'date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->post('/movimientos', ['type' => 'income', 'amount' => 5, 'currency' => 'USD', 'date' => now()->toDateString(), 'status' => 'pending'])->assertSessionHasNoErrors();
        $this->assertSame(3, $h->transactions()->count());

        // Se puede eliminar un gasto por pagar
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 9, 'currency' => 'USD', 'date' => now()->toDateString(), 'status' => 'pending']);
        $p = $h->transactions()->withoutGlobalScope('paid')->where('status', 'pending')->first();
        $this->delete("/movimientos/{$p->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, $h->transactions()->withoutGlobalScope('paid')->where('status', 'pending')->count());
    }

    public function test_total_dividido_en_cuotas_reparte_la_parte_de_cada_persona(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $em = $h->people()->create(['name' => 'Emely', 'color' => '#ec4899']);

        // Total 100 en 5 cuotas cada 7 días, Emely 30 %: cuotas de 20 -> 6 ella, 14 yo
        $this->post('/gastos-fijos', [
            'name' => 'Curso', 'amount' => 100, 'amount_mode' => 'total', 'frequency' => 'days', 'interval_days' => 7,
            'start_date' => now()->toDateString(), 'installments' => 5,
            'shares' => [['person_id' => $em->id, 'percent' => 30]], 'share_mode' => 'split',
        ])->assertSessionHasNoErrors();

        $fx = $h->fixedExpenses()->where('name', 'Curso')->first();
        $this->assertSame('20.00', (string) $fx->amount);
        $this->assertSame('100.00', (string) $fx->total_amount);
        $this->assertSame(14.0, $fx->myAmount());

        $debts = $h->debts()->where('fixed_expense_id', $fx->id)->orderBy('fixed_due')->get();
        $this->assertCount(5, $debts);
        $this->assertSame(['6.00'], $debts->pluck('amount')->map(fn ($v) => (string) $v)->unique()->values()->all());
        $this->assertEquals(30.0, (float) $debts->sum('amount'));

        // Pagar la primera cuota: yo registro 14 (mi parte) y la de Emely queda ligada
        $this->post("/gastos-fijos/{$fx->id}/pagar", ['due' => now()->toDateString()])->assertSessionHasNoErrors();
        $tx = $h->transactions()->where('fixed_expense_id', $fx->id)->first();
        $this->assertSame('14.00', (string) $tx->amount);
        $this->assertSame('20.00', (string) $tx->total_amount);

        // "Total" sin cuotas es un error
        $this->post('/gastos-fijos', ['name' => 'X', 'amount' => 100, 'amount_mode' => 'total', 'frequency' => 'days', 'interval_days' => 7, 'start_date' => now()->toDateString()])
            ->assertSessionHasErrors('installments');
    }

    public function test_deudas_y_compartidos_muestran_detalle_e_historial(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $cuenta = User::create(['name' => 'Ana Hermana', 'email' => 'hist@example.test', 'password' => 'password-123',
            'household_id' => Household::create(['name' => 'Fam Ana'])->id, 'role' => 'owner']);
        $ana = $h->people()->create(['name' => 'Ana', 'color' => '#ec4899', 'user_id' => $cuenta->id]);

        // Dos partes compartidas: una abierta y otra ya saldada
        $abierta = $h->debts()->create(['user_id' => $u->id, 'kind' => 'shared', 'person' => 'Ana', 'person_id' => $ana->id, 'amount' => 30, 'percent' => 30, 'due_date' => today()->addDays(5), 'note' => 'Gasto compartido: Curso']);
        $saldada = $h->debts()->create(['user_id' => $u->id, 'kind' => 'shared', 'person' => 'Ana', 'person_id' => $ana->id, 'amount' => 12, 'percent' => 20, 'note' => 'Gasto compartido: Viejo']);
        $saldada->payments()->create(['amount' => 12, 'date' => today()->subDay()]);
        $saldada->update(['settled_at' => now()]);

        // Titular: detalle (monto, %, fechas) y su historial
        $this->get('/deudas')->assertOk()->assertSee('Registrada')->assertSee('$100.00')->assertSee('30 %')
            ->assertSee('Historial')->assertSee('Saldadas (1)')->assertSee('Pagos');

        // Ana: ve monto total del gasto, su %, su parte y su historial
        $this->actingAs($cuenta);
        $this->get('/compartidos')->assertOk()->assertSee('Gasto total')->assertSee('Tu porcentaje')->assertSee('$100.00')->assertSee('30 %')
            ->assertSee('Historial')->assertSee('Saldados (1)')->assertSee('Viejo');
    }

    public function test_home_muestra_saldo_y_saldo_restando_lo_que_falta_pagar(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $day = now()->startOfMonth()->addDays(min(now()->day, 27) - 1)->toDateString();

        $this->post('/movimientos', ['type' => 'income', 'amount' => 1000, 'currency' => 'USD', 'date' => $day])->assertSessionHasNoErrors();
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 100, 'currency' => 'USD', 'date' => $day, 'status' => 'paid'])->assertSessionHasNoErrors();
        // Imprevisto por pagar de este mes: se descuenta del saldo proyectado
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 50, 'currency' => 'USD', 'date' => $day, 'status' => 'pending', 'note' => 'Plomero'])->assertSessionHasNoErrors();
        // Gasto a futuro (mes siguiente): se registra por pagar pero NO descuenta este mes
        $next = now()->addMonthNoOverflow()->startOfMonth()->addDays(9)->toDateString();
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 200, 'currency' => 'USD', 'date' => $next, 'note' => 'Seguro'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $h->transactions()->withoutGlobalScope('paid')->where('note', 'Seguro')->first()->status);
        // Gasto fijo sin pagar de este mes (tu parte)
        $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Internet', 'amount' => 30, 'due_day' => 28, 'interval_unit' => 'month', 'interval_count' => 1]);

        $f = app(\App\Services\FinanceService::class)->forecast($h, now(), 900.0, 30.0);
        $this->assertSame(50.0, $f['planned']);
        $this->assertSame(820.0, $f['projected']);
        $this->assertSame(200.0, $f['later_total']);

        $this->get('/')->assertOk()->assertSee('¿Ya ingresaste tu presupuesto?')->assertSee('Saldo que tienes')
            ->assertSee('Saldo restando lo que falta pagar')->assertSee('Plomero')
            ->assertSee('Gastos fijos por pagar')->assertSee('imprevistos y programados')->assertSee('Restando lo que falta por pagar');

        // Un ingreso no puede tener fecha futura
        $this->post('/movimientos', ['type' => 'income', 'amount' => 5, 'currency' => 'USD', 'date' => $next])->assertSessionHasErrors('date');
    }

    public function test_se_ve_cuando_termina_el_gasto_en_cuotas_y_el_calendario_completo(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $cuenta = User::create(['name' => 'Emely', 'email' => 'cal@example.test', 'password' => 'password-123',
            'household_id' => Household::create(['name' => 'Fam E'])->id, 'role' => 'owner']);
        $em = $h->people()->create(['name' => 'Emely', 'color' => '#ec4899', 'user_id' => $cuenta->id]);

        $start = now()->toDateString();
        $this->post('/gastos-fijos', [
            'name' => 'Curso', 'amount' => 100, 'amount_mode' => 'total', 'frequency' => 'days', 'interval_days' => 7,
            'start_date' => $start, 'installments' => 5, 'shares' => [['person_id' => $em->id, 'percent' => 30]],
        ])->assertSessionHasNoErrors();

        $fx = $h->fixedExpenses()->first();
        $this->assertCount(5, $fx->schedule());
        $this->assertSame(now()->addDays(28)->toDateString(), $fx->endsOn()->toDateString());

        $this->get('/gastos-fijos')->assertOk()->assertSee('0 de 5 cuotas pagadas')->assertSee('termina el')
            ->assertSee(now()->addDays(28)->translatedFormat('d M Y'))->assertSee('Ver todas las cuotas');

        $this->actingAs($cuenta);
        $this->get('/compartidos')->assertOk()->assertSee('Última cuota')->assertSee('5 cuotas en total')
            ->assertSee(now()->addDays(28)->translatedFormat('d M Y'))->assertSee('5/5');
    }

    public function test_eliminar_o_pausar_un_gasto_fijo_no_cambia_los_meses_pasados(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $prev = now()->subMonthNoOverflow();

        // Renta Casa Azul pagado el mes pasado (con su gasto real) y Gym sin pagar el mes pasado
        $alq = $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Renta Casa Azul', 'amount' => 300, 'due_day' => 5, 'interval_unit' => 'month', 'interval_count' => 1, 'created_at' => $prev->copy()->subMonths(2)]);
        $gym = $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Gym Torre', 'amount' => 40, 'due_day' => 6, 'interval_unit' => 'month', 'interval_count' => 1, 'created_at' => $prev->copy()->subMonths(2)]);
        $this->post("/gastos-fijos/{$alq->id}/pagar", ['due' => $prev->copy()->day(5)->toDateString()])->assertSessionHasNoErrors();

        $mes = $prev->format('Y-m');
        $this->get("/gastos-fijos?mes={$mes}")->assertOk()->assertSee('Renta Casa Azul')->assertSee('Gym Torre');
        $gastoAntes = $h->transactions()->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');

        // Eliminar el pagado y pausar el otro
        $this->delete("/gastos-fijos/{$alq->id}")->assertSessionHasNoErrors();
        $this->patch("/gastos-fijos/{$gym->id}", ['toggle' => 1])->assertSessionHasNoErrors();

        // Mes pasado: el pago sigue ahí y el Gym (que aún era válido ese mes) también
        $this->get("/gastos-fijos?mes={$mes}")->assertOk()->assertSee('Renta Casa Azul')->assertSee('Gym Torre');
        $this->assertSame(1, $h->transactions()->where('fixed_expense_id', $alq->id)->count());
        $this->assertSoftDeleted('fixed_expenses', ['id' => $alq->id]);

        // Mes en curso y siguientes: ya no aparecen
        $this->get('/gastos-fijos')->assertOk()->assertDontSee('Renta Casa Azul');
    }

    public function test_presupuesto_por_gastar_aparece_como_gasto_que_se_viene(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $day = now()->startOfMonth()->toDateString();

        $cat = $h->categories()->create(['name' => 'Gasolina', 'type' => 'expense', 'color' => '#f59e0b', 'monthly_budget' => 200]);
        $this->post('/movimientos', ['type' => 'income', 'amount' => 1000, 'currency' => 'USD', 'date' => $day])->assertSessionHasNoErrors();
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 60, 'currency' => 'USD', 'date' => $day, 'status' => 'paid', 'category_id' => $cat->id])->assertSessionHasNoErrors();
        // Un pendiente de la misma categoría ya está comprometido: no se cuenta doble
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 40, 'currency' => 'USD', 'date' => $day, 'status' => 'pending', 'category_id' => $cat->id])->assertSessionHasNoErrors();

        $this->get('/')->assertOk()->assertSee('Presupuesto por gastar')->assertSee('Gasolina')->assertSee('$100.00')
            ->assertSee('Si gastas todo tu presupuesto');

        // Al gastar todo el tope ya no queda nada por venir
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 100, 'currency' => 'USD', 'date' => $day, 'status' => 'paid', 'category_id' => $cat->id]);
        $this->get('/')->assertOk()->assertDontSee('Presupuesto por gastar');
    }

    public function test_gasto_compartido_con_varias_personas(): void
    {
        Notification::fake();
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $a = $h->people()->create(['name' => 'Ana', 'color' => '#ec4899']);
        $b = $h->people()->create(['name' => 'Beto', 'color' => '#10b981']);

        // Gasto normal: 100 con Ana 20 % y Beto 30 % -> a ti te toca 50
        $this->post('/movimientos', [
            'type' => 'expense', 'amount' => 100, 'currency' => 'USD', 'date' => now()->toDateString(), 'note' => 'Cena',
            'shares' => [['person_id' => $a->id, 'percent' => 20], ['person_id' => $b->id, 'percent' => 30]], 'share_mode' => 'split',
        ])->assertSessionHasNoErrors();
        $tx = $h->transactions()->first();
        $this->assertSame('50.00', (string) $tx->amount);
        $this->assertSame('100.00', (string) $tx->total_amount);
        $this->assertSame(['20.00', '30.00'], $h->debts()->orderBy('id')->pluck('amount')->map(fn ($v) => (string) $v)->all());

        // Más de 100 %: error
        $this->post('/movimientos', [
            'type' => 'expense', 'amount' => 10, 'currency' => 'USD', 'date' => now()->toDateString(),
            'shares' => [['person_id' => $a->id, 'percent' => 70], ['person_id' => $b->id, 'percent' => 40]],
        ])->assertSessionHasErrors('shares');

        // Gasto fijo con dos personas: se programa la cuota de cada una y al pagar se enlazan
        $this->post('/gastos-fijos', [
            'name' => 'Colegio', 'amount' => 200, 'frequency' => 'monthly', 'due_day' => now()->day,
            'shares' => [['person_id' => $a->id, 'percent' => 25], ['person_id' => $b->id, 'percent' => 25]],
        ])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->where('name', 'Colegio')->first();
        $this->assertCount(2, $fx->participants());
        $this->assertSame('50.00', (string) $fx->share_percent);
        $this->assertSame(2, $h->debts()->where('fixed_expense_id', $fx->id)->whereDate('fixed_due', today()->toDateString())->count());

        $this->post("/gastos-fijos/{$fx->id}/pagar", ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $fxTx = $h->transactions()->where('fixed_expense_id', $fx->id)->first();
        $this->assertSame('100.00', (string) $fxTx->amount);
        $this->assertSame(2, $h->debts()->where('transaction_id', $fxTx->id)->count());

        // Quitar a una persona retira su cuota programada futura sin tocar la otra
        $fy = $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Gym', 'amount' => 60, 'due_day' => 5, 'interval_unit' => 'month', 'interval_count' => 1]);
        $this->patch("/gastos-fijos/{$fy->id}", ['name' => 'Gym', 'amount' => 60, 'frequency' => 'monthly', 'due_day' => 5,
            'shares' => [['person_id' => $a->id, 'percent' => 20], ['person_id' => $b->id, 'percent' => 20]]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $h->debts()->where('fixed_expense_id', $fy->id)->pluck('person_id')->unique()->values()->all());
        $this->patch("/gastos-fijos/{$fy->id}", ['name' => 'Gym', 'amount' => 60, 'frequency' => 'monthly', 'due_day' => 5,
            'shares' => [['person_id' => $b->id, 'percent' => 20]]])->assertSessionHasNoErrors();
        $this->assertSame([$b->id], $h->debts()->where('fixed_expense_id', $fy->id)->pluck('person_id')->unique()->values()->all());
    }

    public function test_modo_de_pago_solo_se_guarda_cuando_hay_persona(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);
        $ana = $h->people()->create(['name' => 'Ana', 'color' => '#ec4899']);

        // El formulario condiciona porcentaje y modo a haber elegido una persona
        $html = $this->get('/gastos-fijos')->assertOk()->getContent();
        $this->assertStringContainsString('x-show="rows.length"', $html);

        // Sin persona: lo paga el titular completo y no se guarda reparto
        $this->post('/gastos-fijos', ['name' => 'Luz', 'amount' => 80, 'frequency' => 'monthly', 'due_day' => 8])->assertSessionHasNoErrors();
        $fx = $h->fixedExpenses()->where('name', 'Luz')->first();
        $this->assertNull($fx->share_person_id);
        $this->assertNull($fx->share_percent);
        $this->assertFalse($fx->isSplitShared());
        $this->assertSame(80.0, $fx->myAmount());

        // Aunque llegue un modo "reembolso" sin persona, no hay reparto
        $this->post('/gastos-fijos', ['name' => 'Agua', 'amount' => 20, 'frequency' => 'monthly', 'due_day' => 9, 'share_mode' => 'reimburse'])->assertSessionHasNoErrors();
        $this->assertNull($h->fixedExpenses()->where('name', 'Agua')->first()->share_person_id);
        $this->post("/gastos-fijos/{$h->fixedExpenses()->where('name', 'Agua')->first()->id}/pagar", ['month' => now()->format('Y-m')]);
        $this->assertSame(0, $h->debts()->count());
    }

    public function test_inicio_muestra_carrusel_y_total_de_solo_gastos_fijos(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);

        // Sin datos: diapositivas invitando a crear fijos y una meta, y el mercado
        $this->get('/')->assertOk()
            ->assertSee('aria-roledescription="carrusel"', false)
            ->assertSee('¿Ya pagaste tus gastos fijos?')
            ->assertSee('¿Vas al mercado?')
            ->assertSee('¿Quieres crear una meta?')
            ->assertSee('¿Cuánto gastaste el mes pasado?');

        // Con un fijo pendiente, la diapositiva dice cuánto falta; y aparece el total de solo fijos
        $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Alquiler', 'amount' => 300, 'due_day' => 5]);
        $h->fixedExpenses()->create(['user_id' => $u->id, 'name' => 'Internet', 'amount' => 50, 'due_day' => 10]);
        $h->transactions()->create(['user_id' => $u->id, 'type' => 'income', 'amount' => 1000, 'original_amount' => 1000, 'currency' => 'USD', 'date' => now()]);
        $this->get('/')->assertOk()
            ->assertSee('Solo gastos fijos')
            ->assertSee('Total de gastos fijos de')
            ->assertSee('$350.00')
            ->assertSee('35 %')          // 350 de 1000 de ingresos
            ->assertSee('por pagar');

        // Con una meta: la diapositiva muestra su avance en lugar de invitar a crearla
        $g = $h->goals()->create(['name' => 'Moto Bera 250cc', 'target_amount' => 3000, 'deadline' => now()->addYear()]);
        $this->get('/')->assertOk()->assertSee('Tu meta «Moto Bera 250cc» va al 0 %', false)->assertDontSee('¿Quieres crear una meta?');

        // Con el mes anterior con datos, muestra lo gastado
        $h->transactions()->create(['user_id' => $u->id, 'type' => 'expense', 'amount' => 420, 'original_amount' => 420, 'currency' => 'USD', 'date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);
        $this->get('/')->assertOk()->assertSee('$420.00')->assertSee('gastaste');

        // Todo pagado: la primera diapositiva lo celebra
        $svc = app(\App\Services\FixedExpenseService::class);
        foreach ($svc->month($h, now()) as $row) {
            $this->post("/gastos-fijos/{$row['fixed']->id}/pagar", ['due' => $row['key']]);
        }
        $this->get('/')->assertOk()->assertSee('están pagados');
    }

    public function test_solicitud_dentro_de_la_app_para_una_cuenta_existente(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $h = $owner->household;
        $otra = Household::create(['name' => 'Fam Ana', 'country' => 'VE']);
        $ana = User::create(['name' => 'Ana', 'email' => 'Ana.Real@Example.test', 'password' => 'password-123', 'household_id' => $otra->id, 'role' => 'owner']);
        $edu = $h->categories()->where('name', 'Educación')->whereNull('parent_id')->first();

        $this->actingAs($owner);
        $p = $h->people()->create(['name' => 'Ana', 'relation' => 'hermano', 'color' => '#ec4899']);
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 1000, 'currency' => 'USD', 'note' => 'Universidad de Ana', 'category_id' => $edu->id, 'date' => now()->toDateString()]);
        $tx = $h->transactions()->first();
        $this->post("/movimientos/{$tx->id}/compartir", ['shares' => [$p->id => 40]])->assertSessionHasNoErrors();

        // 1) Invitar con un correo que ya tiene cuenta (sin importar mayúsculas): se avisa que verá una solicitud
        $this->post("/personas/{$p->id}/invitar", ['email' => 'ana.real@example.test'])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya tiene cuenta') && str_contains($m, 'solicitud'));
        $inv = $h->invitations()->whereNotNull('person_id')->first();
        $this->get('/familia')->assertOk()->assertSee('Solicitud enviada: debe aceptarla en su cuenta');

        // 2) Ana la ve dentro de la app (sin usar el enlace del correo) y NO ve aún los gastos
        auth()->logout();
        $this->actingAs($ana->fresh());
        $this->get('/')->assertOk()->assertSee('quiere compartir gastos contigo')->assertSee('Aceptar')->assertSee('Rechazar');
        $this->get('/compartidos')->assertOk()->assertDontSee('Universidad de Ana');

        // 3) Otra cuenta con otro correo no puede aceptar ni rechazar la solicitud de Ana
        auth()->logout();
        $intruso = User::create(['name' => 'Intruso', 'email' => 'intruso@example.test', 'password' => 'password-123', 'household_id' => Household::create(['name' => 'X', 'country' => 'VE'])->id, 'role' => 'owner']);
        $this->actingAs($intruso);
        $this->get('/')->assertOk()->assertDontSee('quiere compartir gastos contigo');
        $this->post("/solicitudes/{$inv->id}/aceptar")->assertNotFound();
        $this->post("/solicitudes/{$inv->id}/rechazar")->assertNotFound();

        // 4) Ana acepta con un toque: queda vinculada y ve lo compartido antes de vincularse
        auth()->logout();
        $this->actingAs($ana->fresh());
        $this->post("/solicitudes/{$inv->id}/aceptar")->assertRedirect('/compartidos');
        $this->assertSame($ana->id, $p->fresh()->user_id);
        $this->assertSame($otra->id, $ana->fresh()->household_id);   // sigue en su propia familia
        $this->get('/compartidos')->assertOk()->assertSee('Universidad de Ana')->assertSee('$400.00');
        $this->get('/')->assertOk()->assertDontSee('quiere compartir gastos contigo');
        $this->get('/movimientos')->assertOk()->assertDontSee('Universidad de Ana');
    }

    public function test_rechazar_la_solicitud_y_casos_borde(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $h = $owner->household;
        $otra = Household::create(['name' => 'Fam Luis', 'country' => 'VE']);
        $luis = User::create(['name' => 'Luis', 'email' => 'luis@example.test', 'password' => 'password-123', 'household_id' => $otra->id, 'role' => 'owner']);
        $p = $h->people()->create(['name' => 'Luis', 'color' => '#10b981']);

        $this->actingAs($owner);
        $this->post("/personas/{$p->id}/invitar", ['email' => 'luis@example.test'])->assertSessionHasNoErrors();
        $inv = $h->invitations()->whereNotNull('person_id')->first();

        // Rechaza: no se vincula y el titular lo ve en Familia
        auth()->logout();
        $this->actingAs($luis);
        $this->post("/solicitudes/{$inv->id}/rechazar")->assertSessionHasNoErrors();
        $this->assertNotNull($inv->fresh()->declined_at);
        $this->assertNull($p->fresh()->user_id);
        $this->get('/')->assertOk()->assertDontSee('quiere compartir gastos contigo');
        $this->post("/solicitudes/{$inv->id}/aceptar")->assertNotFound();   // ya no está pendiente

        auth()->logout();
        $this->actingAs($owner);
        $this->get('/familia')->assertOk()->assertSee('Rechazó la solicitud');

        // Puede quitar el aviso e invitar de nuevo
        $this->delete("/familia/invitaciones/{$inv->id}")->assertSessionHasNoErrors();
        $this->post("/personas/{$p->id}/invitar", ['email' => 'luis@example.test'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->actingAs($luis);
        $this->get('/')->assertOk()->assertSee('quiere compartir gastos contigo');

        // Una solicitud vencida no aparece
        $h->invitations()->whereNotNull('person_id')->whereNull('declined_at')->update(['expires_at' => now()->subDay()]);
        $this->get('/')->assertOk()->assertDontSee('quiere compartir gastos contigo');

        // Invitar a alguien que ya es de tu misma familia: no hace falta
        auth()->logout();
        $this->actingAs($owner);
        $miembro = User::create(['name' => 'Mama', 'email' => 'mama@example.test', 'password' => 'password-123', 'household_id' => $h->id, 'role' => 'member']);
        $p2 = $h->people()->create(['name' => 'Mamá', 'color' => '#6366f1']);
        $this->post("/personas/{$p2->id}/invitar", ['email' => 'mama@example.test'])->assertSessionHas('error');
        $this->assertSame(0, $h->invitations()->where('person_id', $p2->id)->count());
    }

    public function test_al_agregar_una_persona_se_pide_el_correo_y_se_invita_de_una_vez(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $h = $owner->household;
        $this->actingAs($owner);

        // El formulario ofrece el campo de correo y "invitarle ahora"
        $this->get('/familia')->assertOk()->assertSee('name="email"', false)->assertSee('Invitarle ahora');

        // 1) Correo nuevo: se guarda en la ficha y se envía la invitación para crear su cuenta
        $this->post('/personas', ['name' => 'Sofía', 'relation' => 'hermano', 'email' => 'Sofia@Example.test', 'send_invite' => 1])->assertSessionHasNoErrors();
        $sofia = $h->people()->where('name', 'Sofía')->first();
        $this->assertSame('sofia@example.test', $sofia->email);
        Notification::assertSentOnDemand(ParticipantInvitationNotification::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'sofia@example.test');
        $this->assertSame(1, $h->invitations()->where('person_id', $sofia->id)->count());
        $this->get('/familia')->assertOk()->assertSee('sofia@example.test')->assertSee('Invitación pendiente');

        // 2) Con el correo de una cuenta existente: aparece la solicitud dentro de la app
        $cuenta = User::create(['name' => 'Beto', 'email' => 'beto@example.test', 'password' => 'password-123',
            'household_id' => Household::create(['name' => 'Fam Beto', 'country' => 'VE'])->id, 'role' => 'owner']);
        $this->post('/personas', ['name' => 'Beto', 'email' => 'beto@example.test', 'send_invite' => 1])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya tiene cuenta') && str_contains($m, 'solicitud'));
        auth()->logout();
        $this->actingAs($cuenta);
        $this->get('/')->assertOk()->assertSee('quiere compartir gastos contigo');

        // 3) Con correo pero sin marcar "invitarle ahora": solo se guarda el correo
        auth()->logout();
        $this->actingAs($owner);
        $this->post('/personas', ['name' => 'Carla', 'email' => 'carla@example.test', 'send_invite' => 0])->assertSessionHasNoErrors();
        $carla = $h->people()->where('name', 'Carla')->first();
        $this->assertSame('carla@example.test', $carla->email);
        $this->assertSame(0, $h->invitations()->where('person_id', $carla->id)->count());

        // 4) Sin correo: igual que antes
        $this->post('/personas', ['name' => 'Dani'])->assertSessionHasNoErrors();
        $this->assertNull($h->people()->where('name', 'Dani')->first()->email);

        // 5) Correo inválido se rechaza; y se puede editar el correo de una persona
        $this->post('/personas', ['name' => 'Eli', 'email' => 'no-es-correo'])->assertSessionHasErrors('email');
        $this->patch("/personas/{$carla->id}", ['name' => 'Carla', 'email' => 'nuevo@example.test'])->assertSessionHasNoErrors();
        $this->assertSame('nuevo@example.test', $carla->fresh()->email);

        // 6) "Invitar a que tenga su cuenta" ya viene con el correo de la ficha
        $this->get('/familia')->assertOk()->assertSee('value="nuevo@example.test"', false);
        $this->post("/personas/{$carla->id}/invitar")->assertSessionHas('ok');
        Notification::assertSentOnDemand(ParticipantInvitationNotification::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'nuevo@example.test');
    }

    public function test_persona_nueva_desde_el_formulario_de_gasto_puede_llevar_correo(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $h = $owner->household;
        $this->actingAs($owner);

        // El formulario rápido ofrece el correo
        $this->get('/')->assertOk()->assertSee('name="new_person_email"', false);

        // Gasto "para" una persona nueva con correo: se crea, queda con su correo y se le invita
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 30, 'currency' => 'USD', 'date' => now()->toDateString(),
            'new_person' => 'Marta', 'new_person_email' => 'Marta@Example.test', 'new_person_invite' => 1])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Gasto registrado.') && str_contains($m, 'Invitación enviada'));
        $marta = $h->people()->where('name', 'Marta')->first();
        $this->assertSame('marta@example.test', $marta->email);
        $this->assertSame($marta->id, $h->transactions()->first()->person_id);
        Notification::assertSentOnDemand(ParticipantInvitationNotification::class, fn ($n, $ch, $nf) => $nf->routes['mail'] === 'marta@example.test');
        $this->assertSame(1, $h->invitations()->where('person_id', $marta->id)->count());

        // Sin marcar "invitarle": solo guarda el correo
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 12, 'currency' => 'USD', 'date' => now()->toDateString(),
            'new_person' => 'Nico', 'new_person_email' => 'nico@example.test', 'new_person_invite' => 0])->assertSessionHasNoErrors();
        $nico = $h->people()->where('name', 'Nico')->first();
        $this->assertSame('nico@example.test', $nico->email);
        $this->assertSame(0, $h->invitations()->where('person_id', $nico->id)->count());

        // Solo nombre: igual que antes; correo inválido se rechaza sin crear nada
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 5, 'currency' => 'USD', 'date' => now()->toDateString(), 'new_person' => 'Olga'])->assertSessionHasNoErrors();
        $this->assertNull($h->people()->where('name', 'Olga')->first()->email);
        $antes = $h->people()->count();
        $this->post('/movimientos', ['type' => 'expense', 'amount' => 5, 'currency' => 'USD', 'date' => now()->toDateString(), 'new_person' => 'Pepe', 'new_person_email' => 'malo'])
            ->assertSessionHasErrors('new_person_email');
        $this->assertSame($antes, $h->people()->count());
    }

    public function test_deuda_con_detalles_que_componen_el_total(): void
    {
        $u = $this->owner();
        $h = $u->household;
        $this->actingAs($u);

        // 1) "Carro $5.000" con detalles: el total manda
        $this->post('/deudas', ['kind' => 'owe', 'person' => 'Carro', 'amount' => 5000, 'currency' => 'USD', 'items' => [
            ['name' => 'Enganche', 'amount' => 2000], ['name' => 'Cuotas', 'amount' => 2400], ['name' => '', 'amount' => 300], ['name' => 'vacío', 'amount' => ''],
        ]])->assertSessionHasNoErrors();
        $d = $h->debts()->where('person', 'Carro')->first();
        $this->assertSame('5000.00', (string) $d->amount);
        $this->assertSame(3, $d->items()->count());                         // la fila sin monto se descarta
        $this->assertSame('Detalle', $d->items()->where('amount', 300)->first()->name);
        $this->assertEquals(4700.0, $d->detailedTotal());
        $this->assertEquals(300.0, $d->undetailed());                         // sin detallar: 300
        $this->get('/deudas')->assertOk()->assertSee('Detalles (3)')->assertSee('Enganche')->assertSee('Sin detallar: $300.00');

        // 2) Si no se escribe el total, es la suma de los detalles
        $this->post('/deudas', ['kind' => 'lent', 'person' => 'Préstamo Pedro', 'currency' => 'USD', 'items' => [['name' => 'Taller', 'amount' => 120], ['name' => 'Repuestos', 'amount' => 80.5]]])->assertSessionHasNoErrors();
        $this->assertSame('200.50', (string) $h->debts()->where('person', 'Préstamo Pedro')->first()->amount);

        // 3) Los detalles no pueden superar el total
        $this->post('/deudas', ['kind' => 'owe', 'person' => 'Mal', 'amount' => 100, 'currency' => 'USD', 'items' => [['name' => 'A', 'amount' => 90], ['name' => 'B', 'amount' => 30]]])
            ->assertSessionHasErrors('amount');
        $this->assertNull($h->debts()->where('person', 'Mal')->first());
        $this->post('/deudas', ['kind' => 'owe', 'person' => 'Nada', 'currency' => 'USD'])->assertSessionHasErrors('amount');

        // 4) Agregar un detalle a una deuda existente (sin pasarse del total)
        $this->post("/deudas/{$d->id}/detalles", ['name' => 'Seguro', 'amount' => 400, 'currency' => 'USD'])->assertSessionHasErrors('amount');   // 4.700 + 400 > 5.000
        $this->post("/deudas/{$d->id}/detalles", ['name' => 'Seguro', 'amount' => 300, 'currency' => 'USD'])->assertSessionHasNoErrors();
        $this->assertEquals(0.0, $d->fresh()->undetailed());
        $seguro = $d->items()->where('name', 'Seguro')->first();

        // 5) Marcar un detalle como pagado registra el pago por su monto; deshacer lo retira
        $enganche = $d->items()->where('name', 'Enganche')->first();
        $this->post("/deudas/{$d->id}/detalles/{$enganche->id}/pagar")->assertSessionHasNoErrors();
        $d = $d->fresh();
        $this->assertEquals(2000.0, $d->paid());
        $this->assertEquals(3000.0, $d->remaining());
        $this->assertTrue($enganche->fresh()->isPaid());
        $this->get('/deudas')->assertOk()->assertSee('Pagado');
        $this->post("/deudas/{$d->id}/detalles/{$enganche->id}/pagar");
        $this->assertEquals(0.0, $d->fresh()->paid());

        // 6) Pagar todos los detalles salda la deuda
        foreach ($d->items as $it) {
            $this->post("/deudas/{$d->id}/detalles/{$it->id}/pagar");
        }
        $this->assertNotNull($d->fresh()->settled_at);
        $this->assertEquals(0.0, $d->fresh()->remaining());

        // 7) Quitar un detalle pagado conserva el pago (solo se desliga)
        $this->delete("/deudas/{$d->id}/detalles/{$seguro->id}")->assertSessionHasNoErrors();
        $this->assertNull($d->items()->where('name', 'Seguro')->first());
        $this->assertEquals(300.0, (float) $d->payments()->where('note', 'Seguro')->sum('amount'));

        // 8) Editar el total: no puede ser menor a lo detallado/pagado
        $this->patch("/deudas/{$d->id}", ['person' => 'Carro nuevo', 'amount' => 1000])->assertSessionHasErrors('amount');
        $this->patch("/deudas/{$d->id}", ['person' => 'Carro nuevo', 'amount' => 6000, 'due_date' => now()->addMonth()->toDateString()])->assertSessionHasNoErrors();
        $d = $d->fresh();
        $this->assertSame('Carro nuevo', $d->person);
        $this->assertSame('6000.00', (string) $d->amount);
        $this->assertNull($d->settled_at);   // vuelve a haber saldo pendiente

        // 9) Otra familia no puede tocar los detalles
        $otra = Household::create(['name' => 'Otra', 'country' => 'VE']);
        $b = User::create(['name' => 'Bob', 'email' => 'bob9@example.test', 'password' => 'password-123', 'household_id' => $otra->id, 'role' => 'owner']);
        auth()->logout();
        $this->actingAs($b);
        $it = $d->items()->first();
        $this->post("/deudas/{$d->id}/detalles", ['name' => 'X', 'amount' => 1, 'currency' => 'USD'])->assertNotFound();
        $this->post("/deudas/{$d->id}/detalles/{$it->id}/pagar")->assertNotFound();
        $this->patch("/deudas/{$d->id}", ['person' => 'Hack', 'amount' => 1])->assertNotFound();
    }

    public function test_deuda_con_una_persona_o_cuenta_de_finanve(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $h = $owner->household;
        $otra = Household::create(['name' => 'Fam Ana', 'country' => 'VE']);
        $ana = User::create(['name' => 'Ana', 'email' => 'ana.cuenta@example.test', 'password' => 'password-123', 'household_id' => $otra->id, 'role' => 'owner']);
        $this->actingAs($owner);

        // 1) Yo debo a alguien con cuenta (por correo): se crea la persona, la deuda y la solicitud dentro de la app
        $this->post('/deudas', ['kind' => 'owe', 'person' => 'Préstamo del carro', 'amount' => 800, 'currency' => 'USD', 'counterparty_email' => 'Ana.Cuenta@Example.test'])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya tiene cuenta'));
        $d = $h->debts()->where('person', 'Préstamo del carro')->first();
        $this->assertNotNull($d->person_id);
        $p = $h->people()->find($d->person_id);
        $this->assertSame('ana.cuenta@example.test', $p->email);
        $this->assertSame('Ana', $p->name);                                 // toma el nombre de su cuenta
        $this->assertSame(1, $h->invitations()->where('person_id', $p->id)->count());
        $this->get('/deudas')->assertOk()->assertSee('le debes a')->assertSee('pendiente de aceptar');

        // 2) Aún sin aceptar, Ana no ve la deuda; sí ve la solicitud
        auth()->logout();
        $this->actingAs($ana);
        $this->get('/compartidos')->assertOk()->assertDontSee('Préstamo del carro');
        $this->get('/')->assertOk()->assertSee('quiere compartir gastos contigo');

        // 3) Al aceptar, ve que le deben, con el monto, y confirma lo que recibe
        $inv = $h->invitations()->where('person_id', $p->id)->first();
        $this->post("/solicitudes/{$inv->id}/aceptar")->assertRedirect('/compartidos');
        $this->get('/compartidos')->assertOk()->assertSee('Te deben')->assertSee('Préstamo del carro')->assertSee('$800.00')
            ->assertSee('Ya me pagó')->assertSee('te debe esto');
        $this->post("/compartidos/{$d->id}/pagar", ['amount' => 300])->assertSessionHasNoErrors();
        $this->assertEquals(500.0, $d->fresh('payments')->remaining());
        $this->assertSame($ana->id, $d->payments()->first()->reported_by);
        Notification::assertSentTo($owner, SharedPaymentReportedNotification::class, function ($n, $ch) use ($owner) {
            $html = (string) $n->toMail($owner)->render();
            return str_contains($html, 'confirmó que recibió');
        });
        // El monto que "te deben" no se mezcla con lo que ella debe pagar
        $this->get('/')->assertOk()->assertDontSee('Te falta pagar $');

        // 4) El deudor ve quién confirmó el cobro
        auth()->logout();
        $this->actingAs($owner);
        $this->get('/deudas')->assertOk()->assertSee('reportado por Ana')->assertSee('con cuenta');

        // 5) Elegir una persona de la lista (sin escribir nombre): usa su nombre
        $beto = $h->people()->create(['name' => 'Beto', 'color' => '#10b981', 'email' => 'beto@example.test']);
        $this->post('/deudas', ['kind' => 'lent', 'person_id' => $beto->id, 'amount' => 50, 'currency' => 'USD'])->assertSessionHasNoErrors();
        $d2 = $h->debts()->where('person', 'Beto')->first();
        $this->assertSame($beto->id, $d2->person_id);
        $this->assertSame(1, $h->invitations()->where('person_id', $beto->id)->count());   // tiene correo → se le invita

        // 6) A un miembro de la familia: sin invitación (ve todo directamente)
        $miembro = User::create(['name' => 'Mamá', 'email' => 'mama.f@example.test', 'password' => 'password-123', 'household_id' => $h->id, 'role' => 'member']);
        $pm = $h->mePerson($miembro);
        $this->post('/deudas', ['kind' => 'owe', 'person_id' => $pm->id, 'amount' => 20, 'currency' => 'USD'])->assertSessionHasNoErrors();
        $this->assertSame(0, $h->invitations()->where('person_id', $pm->id)->count());
        $this->get('/deudas')->assertOk()->assertSee('familia');

        // 7) Sin nombre ni persona ni correo: error; y una persona de otra familia se rechaza
        $this->post('/deudas', ['kind' => 'owe', 'amount' => 10, 'currency' => 'USD'])->assertSessionHasErrors('person');
        $ajena = $otra->people()->create(['name' => 'Ajena', 'color' => '#000000']);
        $this->post('/deudas', ['kind' => 'owe', 'person_id' => $ajena->id, 'amount' => 10, 'currency' => 'USD'])->assertSessionHasErrors('person_id');

        // 8) El selector muestra a tus personas
        $this->get('/deudas')->assertOk()->assertSee('¿Con quién?')->assertSee('Beto');
    }
}
