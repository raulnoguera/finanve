# Finanve

Aplicación web de **finanzas personales y familiares** en español, pensada para Venezuela (dólares + tasa BCV) y con soporte multipaís. Permite llevar ingresos y gastos, presupuesto, gastos fijos, metas, deudas y **gastos compartidos** entre personas con cuenta en Finanve.

> Presentación comercial: [`docs/Finanve-presentacion.pdf`](docs/Finanve-presentacion.pdf)

## Funcionalidades

| Módulo | Qué hace |
|---|---|
| **Inicio** | Saldo real y saldo proyectado (descontando lo pendiente del mes), presupuesto restante visto como "gasto que viene", próximos gastos y resumen del balance. |
| **Movimientos** | Ingresos y gastos. Los gastos imprevistos pueden registrarse como **por pagar** (o a futuro) y confirmarse después; solo los pagados afectan saldos y reportes. |
| **Presupuesto** | Categorías con monto mensual y sugerencias. |
| **Gastos fijos** | Frecuencia, fecha de fin, **cuotas** con total (`monto total ÷ cuotas`), calendario completo de cuotas, pago/reverso por mes. Al eliminar uno, los meses pasados no cambian (borrado lógico + `ended_at`). |
| **Gastos compartidos** | Un gasto (movimiento o fijo) se reparte con una o varias personas por porcentaje. Cada participante recibe su parte, dividida en cuotas si aplica, y una notificación. |
| **Deudas** | Lo que te deben y lo que debes, con detalle (monto, %, fecha), pagos parciales e historial. |
| **Metas** | Metas de ahorro con aportes y un asesor (`GoalAdvisor`) que calcula cuánto se puede apartar según ingresos, gastos y pendientes. |
| **Reportes** | Resumen por mes/categoría y exportación a CSV. |
| **Familia / Personas** | Hogar con miembros, invitaciones por enlace/correo y vínculo entre cuentas. |
| **Mercado** | Lista de compras con precios. |
| **Tasa de cambio** | Tasa Bs/USD (BCV y paralelo) desde [ve.dolarapi.com](https://ve.dolarapi.com), con histórico en BD y opción manual. |

Países y monedas: `config/countries.php` (VE, US, CO, MX, ES, CL) y `config/currencies.php`.

## Stack

- **Backend:** PHP 8.3+ · Laravel 13
- **Frontend:** Blade + Alpine.js 3 + Tailwind CSS 4 + Chart.js, empaquetado con Vite
- **Base de datos:** SQLite en desarrollo, MySQL en producción
- **Tests:** PHPUnit (`tests/Feature/FinanzasTest.php`)
- **Hosting objetivo:** hosting compartido (Hostinger) con cron

## Puesta en marcha (local)

Requisitos: PHP ≥ 8.3, Composer, Node ≥ 20.

```bash
git clone https://github.com/raulnoguera/finanve.git
cd finanve
composer setup          # instala dependencias, crea .env, genera APP_KEY, migra y compila assets
php artisan db:seed --class=DemoSeeder   # (opcional) datos de ejemplo
composer dev            # servidor + Vite + cola + logs
```

La app queda en <http://localhost:8000>. Regístrate en `/registro` o, si corriste el `DemoSeeder`, entra con `demo@example.test` / `demo-pass-123`.

## Configuración

Copia `.env.example` a `.env`. Variables relevantes:

| Variable | Descripción |
|---|---|
| `APP_URL` | URL pública (se usa en enlaces de invitación y correos). |
| `APP_TIMEZONE`, `APP_LOCALE` | Producción usa `America/Caracas` y `es`. |
| `DB_*` | `sqlite` por defecto; `mysql` en producción (ver `.env.production.example`). |
| `MAIL_*` | SMTP para notificaciones. Con `MAIL_MAILER=log` los correos se escriben en `storage/logs`. |

## Tareas programadas

Configura un cron cada minuto: `php artisan schedule:run`.

| Comando | Frecuencia | Función |
|---|---|---|
| `rates:refresh` | cada hora | Actualiza la tasa de cambio. |
| `fixed:sync-shares` | diario 06:00 | Genera las cuotas/deudas de gastos fijos compartidos. |
| `notify:debts` | diario 08:00 | Avisa deudas por vencer. |
| `notify:fixed` | día 1, 09:00 | Avisa gastos fijos del mes. |
| `notify:monthly` | día 1, 08:30 | Resumen mensual. |
| `cron:status` | manual | Comprueba que el cron está corriendo (latido cada minuto). |

## Pruebas

```bash
php artisan test
```

## Despliegue

- Guía paso a paso para hosting compartido: [`DEPLOY.md`](DEPLOY.md).
- Paquete completo: `./deploy/build-zip.sh` genera `finanzas-deploy.zip`.
- Actualizaciones por SSH: copia `deploy.env.example` a `.deploy.env`, complétalo y ejecuta `./deploy.sh`. El script **verifica que el `.env` remoto apunte al `SITE` configurado** antes de subir nada, para no desplegar sobre otro proyecto por error.

## Arquitectura

Resumen de capas, modelos y reglas de negocio clave en [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md).

## Estructura

```
app/
  Console/Commands/   comandos programados
  Http/Controllers/   un controlador por módulo
  Models/             Eloquent (Transaction, FixedExpense, Debt, Goal, ...)
  Services/           lógica de negocio (FinanceService, FixedShareService, GoalAdvisor, ...)
  Support/            utilidades (Money, ShareInput)
config/               countries.php, currencies.php
database/migrations/  esquema
resources/views/      Blade (components/layouts/app.blade.php es el layout)
routes/web.php        rutas en español (/movimientos, /gastos-fijos, /deudas, ...)
tests/                PHPUnit
deploy/, deploy.sh    empaquetado y despliegue
```

## Seguridad

- Nunca subas `.env`, `.env.*` (salvo los `*.example`), `.deploy.env`, bases `.sqlite` ni `.zip` de despliegue: están en `.gitignore`.
- Login, registro y recuperación de clave tienen `throttle`.
