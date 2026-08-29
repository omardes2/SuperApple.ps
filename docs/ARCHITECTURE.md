# SuperApple ERP/CRM — Architecture

نظام ERP/CRM داخلي متكامل لشركة تسويق وتصميم وخدمات إبداعية.

## 1. Tech Stack

| Layer | Choice |
|-------|--------|
| Backend framework | Laravel (latest stable) |
| Frontend | Blade + Livewire 3 |
| Styling | Tailwind CSS (RTL-first) + Vite |
| Auth roles/permissions | `spatie/laravel-permission` |
| Production DB | MySQL 8 |
| Testing DB | SQLite (in-memory) |
| Queue driver | database (prod: redis-ready) |
| Language | Arabic RTL primary, English-ready (Laravel localization) |

## 2. Core Principles

1. **Strict separation of operational vs financial data.** Employees never see money. Enforced with real Backend Authorization (Policies + Gates + Permissions), never UI-hiding alone.
2. **Service Layer for all financial operations.** Controllers / Livewire components never build accounting entries or mutate balances directly — they call Services (`InvoiceService`, `PaymentService`, `AccountingService`, ...).
3. **No hard-delete of financial records.** Invoices, payments, journal entries, approved payroll use Void / Cancel / Reverse / Adjustment and keep full history.
4. **Every important action is audited** in `audit_logs` (user, action, module, record id, old/new values, IP, timestamp).
5. **Double-entry accounting** runs in the background; ordinary users never touch journal entries directly.
6. **Currency rules are fixed business rules** (see CURRENCY.md) and must never be silently changed.

## 3. Directory / Namespace Layout

```
app/
  Enums/               PHP 8.1 native enums (statuses, currencies, roles...)
  Models/              Eloquent models
  Policies/            Authorization policies per model
  Services/            Financial + domain service classes
  Support/             Numbering, helpers
  Livewire/            Livewire 3 components grouped by module
  Http/
    Requests/          Form Request validation
    Middleware/
  Observers/           Model observers (audit hooks)
  Traits/              Auditable, HasReferenceNumber
database/
  migrations/
  seeders/
  factories/
resources/
  views/
    layouts/           admin + employee layouts (RTL)
    livewire/
  lang/ (ar, en)
docs/
tests/
  Feature/
  Unit/
```

## 4. Roles & Permissions Model

- Uses `spatie/laravel-permission`. Permissions are the atomic unit; roles are collections of permissions and **fully editable** — admins can create new roles later.
- Every financially-sensitive endpoint/policy checks a specific permission (e.g. `invoices.view`, `payroll.view`, `finance.view`).
- Roles seeded: Super Admin, General Manager, Accountant, HR Manager, Project Manager, Team Leader, Employee. Full matrix in PERMISSIONS.md.
- Super Admin bypasses all checks via `Gate::before`.

## 5. Two Distinct Experiences

- **Admin/back-office layout** (`layouts.app`): full sidebar (customers, projects, finance, HR, accounting, reports, settings). Menu items render only when the user holds the relevant permission.
- **Employee layout** (`layouts.employee`): minimal — home, my tasks, my projects, attendance, leaves, notifications. No financial section is ever rendered or routable.
- Menu visibility is cosmetic; the real gate is Policies + `can:` middleware on every route.

## 6. Audit Logging

- `Auditable` trait + generic observer records create/update/delete (and custom events like status changes, invoice void) into `audit_logs`.
- Captures old/new values (dirty attributes), authenticated user, IP, user agent, module name.
- Financial actions additionally log through the service layer with explicit action strings.

## 7. Settings

- `settings` table (key/value, typed, grouped) fronted by a `Settings` service with cache.
- Groups: company, finance, payroll, whatsapp, numbering, attendance.
- Fixed defaults: `default_currency=ILS`, `invoice_currency=USD`.

## 8. Sprint Roadmap

| Sprint | Scope |
|--------|-------|
| **0** | Project setup, auth, roles, permissions, Arabic RTL layout, settings, audit log |
| 1 | Employees, departments, attendance, leaves |
| 2 | Customers, services, projects, tasks + task workflow |
| 3 | Quotations, invoices, invoice items, exchange rates |
| ✅ **4** | Payments, partial payments, ILS/USD conversion, allocation, customer balance, exchange gain/loss — `PaymentService`, `PaymentAllocationService`, `CustomerBalanceService`, `CustomerStatementService` (see PAYMENTS.md, CUSTOMER_BALANCES.md) |
| ✅ **5** | Expenses, suppliers, cash & banks, double-entry accounting, journal entries — `AccountingService`, `LedgerPostingService`, expense/supplier/financial-account services, reports + reconciliation + `accounting:backfill` (see ACCOUNTING.md) |
| ✅ **6** | Payroll, salaries, salary adjustments, employee advances/loans, payroll accounting — `PayrollCalculator`, `PayrollService`, `EmployeeAdvanceService`, `PayrollPaymentService` (see PAYROLL.md) |
| 7 | Subscriptions, recurring invoices, WhatsApp reminders |
| 8 | Dashboards, reports, notifications, global search, UX polish |

Each sprint: migrate → seed → test → lint/build → fix → commit, and is not "done" while tests fail.

## 9. Testing Strategy

- Feature tests assert **authorization** (employee blocked from invoices/payroll, PM blocked from finance unless permitted) and **financial correctness** (exchange rate lock, partial payment USD math, ILS→USD balance, exchange gain/loss, no overpayment, no hard delete, cancelled payment reverses entry, customer balance).
- Unit tests for service math.
- SQLite in-memory for speed; migrations are DB-agnostic.

## Sprint 7 — Subscriptions, Recurring Invoices & WhatsApp
- **Subscriptions** describe recurring contracts; they post no accounting. `SubscriptionBillingService` turns due subscriptions into invoices **through `InvoiceService`** (no new accounting path), with three-layer duplicate prevention (row lock + existence check + unique index) and per-subscription independent transactions. `next_billing_date` advances only after a successful generation. MRR/ARR (`SubscriptionMetricsService`) are contracted-value management metrics, not accounting revenue.
- **WhatsApp** is an outbound channel behind a `WhatsAppProvider` contract (Null/Log/Fake drivers; ready for Meta Cloud/360dialog). Financial code commits first, then dispatches `SendWhatsAppMessageJob` (retry/backoff, bounded) — a WhatsApp failure never rolls back an invoice or payment. Templates render strictly (missing variable → reject). No credentials in code (Settings/ENV only).
- **Payment reminders** (manual + rule-driven daily command) collect in USD; any ILS figure is an estimate at the **latest** rate, never an invoice's frozen rate. Dedupe via a unique log index.
- **Commands/scheduler**: `subscriptions:bill` (daily 02:00) and `payments:send-reminders` (daily 09:00), both with `--dry-run`.
- Tested in `tests/Feature/Sprint7/*` (subscriptions, recurring invoices, duplicate/dry-run, MRR/ARR, phone, template render, manual+automatic reminders, WhatsApp failure isolation, security, provider contract, smoke render) using `FakeWhatsAppProvider` (offline).

## Sprint 8 — Final Integration & Production Readiness
- **Executive dashboard** is role-aware: each widget is permission-gated and its query only runs for authorised users. Accounting revenue comes from the GL (ILS); receivables are USD with a clearly-marked latest-rate ILS estimate; MRR/ARR remain contracted-value metrics. Charts (revenue-vs-expense, cash collection) render as CSS bars (no external JS; CSP-safe). Executive alerts use configurable `dashboard.*` Settings thresholds.
- **Reports centre** (`ReportsService`) adds AR-aging (USD buckets), top-customers, and per-domain report pages, each gated and CSV-exportable (`reports.export`). No financial business rule changed — reports read the existing GL/invoices/payments.
- **Global search** (`GlobalSearchService`) is server-side and permission-gated per category, so it never surfaces a record the user cannot open (an employee searching an exact invoice number gets nothing).
- **Notification centre** categorises Laravel notifications and filters them by live permission; **activity feed** distils significant audit events, permission-filtered; the **audit log** gained user/module/action/date/record filters and a Super-Admin CSV export.
- **Users & roles**: management UI (create/link-employee/activate-deactivate/reset-password/role + direct perms), Roles/Permissions editor, and a Role × permission-group matrix with dangerous-permission warnings.
- **Production readiness**: `app:health-check` and `app:verify-integrity` commands (exit codes, never print secrets) plus a Super-Admin readiness page. Demo seeders (and the weak-password demo accounts) never run in production without `APP_ALLOW_DEMO_SEED`. Base accounting currency (ILS) and invoice currency (USD) are not UI-editable, so they cannot be changed after transactions exist.
- **UX**: reorganised sidebar, breadcrumb support, Arabic-RTL error pages (403/404/419/500/503), unified `Format` helper (USD `$1,250.00` / ILS `1,250.00 ₪` / rate 6dp), signed amounts never rely on colour alone, app version 1.0.0 in the footer.
