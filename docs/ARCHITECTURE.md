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
| 5 | Expenses, suppliers, cash & banks, accounting, journal entries |
| 6 | Payroll, salary adjustments, payroll reports |
| 7 | Subscriptions, recurring invoices, WhatsApp reminders |
| 8 | Dashboards, reports, notifications, global search, UX polish |

Each sprint: migrate → seed → test → lint/build → fix → commit, and is not "done" while tests fail.

## 9. Testing Strategy

- Feature tests assert **authorization** (employee blocked from invoices/payroll, PM blocked from finance unless permitted) and **financial correctness** (exchange rate lock, partial payment USD math, ILS→USD balance, exchange gain/loss, no overpayment, no hard delete, cancelled payment reverses entry, customer balance).
- Unit tests for service math.
- SQLite in-memory for speed; migrations are DB-agnostic.
