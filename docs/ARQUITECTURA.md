# Arquitectura de Finanve

## Capas

```
routes/web.php → Controllers → Services → Models (Eloquent) → BD
                                   ↘ Notifications / Notifier
```

- **Controllers** validan y orquestan; la lógica de negocio vive en `app/Services`.
- **Views** Blade con Alpine.js para interacción; el layout y el modal de nuevo movimiento están en `resources/views/components/layouts/app.blade.php`.
- Todas las rutas están en español y protegidas con `auth` salvo login/registro/recuperación.

## Modelos principales

| Modelo | Notas |
|---|---|
| `Household` / `User` | Un hogar agrupa usuarios; los datos se filtran por hogar. |
| `Person` | Contacto (familiar/amigo) que puede vincularse a una cuenta Finanve. |
| `Transaction` | Ingreso/gasto. Tiene `status` (`paid`/`pending`). |
| `FixedExpense` | Gasto recurrente. `SoftDeletes`, `ended_at`, `total_amount` (cuotas), `shares` (JSON de participantes). |
| `Debt`, `DebtItem`, `DebtPayment` | Deudas entre personas, con detalle y pagos. |
| `Goal`, `GoalContribution` | Metas de ahorro y aportes. |
| `Category` | Categoría con presupuesto mensual. |
| `ExchangeRate` | Histórico de tasas. |
| `Invitation` | Invitaciones a familia y solicitudes de compartir. |

## Reglas de negocio importantes

1. **Gastos pendientes.** `Transaction` tiene un *global scope* `paid`: los pendientes quedan fuera de todos los totales y reportes. Se leen explícitamente con `withoutGlobalScopes()` (p. ej. en `Debt::transaction()` y en la sección "Por pagar"). Un gasto con fecha futura nace pendiente; `confirm()` lo marca pagado.
2. **Cuotas.** Si un gasto fijo tiene `total_amount` y `installments`, la cuota = `total ÷ cuotas` (`FixedExpense::cuotaAmount`). Con participantes, cada cuota se reparte por porcentaje: ej. total 100, 5 cuotas, Emely 30 % → 6 por cuota para Emely y 14 para el dueño.
3. **Compartir.** `Support\ShareInput::parse()` normaliza la lista de participantes (sin duplicados, porcentajes requeridos, suma ≤ 100). `FixedShareService::sync()` programa una deuda por participante y cuota (siempre al menos la próxima) y `notifyAssigned()` avisa al asignado.
4. **Eliminar gasto fijo.** Se hace borrado lógico y se fija `ended_at`; solo se eliminan las deudas futuras no pagadas. Los meses pasados (y lo pagado) se conservan (`FixedExpenseService::month()` usa `withTrashed()`).
5. **Proyección.** `FinanceService::forecast()` calcula saldo, planificado, comprometido y proyectado del mes; `budgetAhead()` muestra el presupuesto aún no gastado como gasto por venir.
6. **Metas.** `GoalAdvisor` estima cuánto se puede apartar descontando gastos fijos y planificados pendientes del mes actual.
7. **Tasa.** `RateService` consulta ve.dolarapi.com cada hora y guarda histórico; si falla, usa el último valor.

## Tareas programadas

Definidas en `routes/console.php` (ver README). Incluyen un latido por minuto para verificar el cron con `php artisan cron:status`.

## Pruebas

`tests/Feature/FinanzasTest.php` cubre flujos de movimientos, gastos fijos, cuotas, compartidos, deudas, metas y notificaciones. Usa SQLite en memoria (`phpunit.xml`).

## Convenciones

- Nombres de rutas/vistas en español para pantallas de usuario; código y modelos en inglés.
- Montos con `Support\Money` (formato según moneda/país del hogar).
- Cambios de esquema siempre con migración nueva; en producción `php artisan migrate --force`.
