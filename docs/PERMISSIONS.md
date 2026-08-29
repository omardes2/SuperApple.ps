# Permissions & Roles Matrix

Permissions are atomic; roles bundle them and are editable. Super Admin bypasses all via `Gate::before`.

## Permission catalog (seeded)

### Operational (non-financial)
- `dashboard.view`
- `customers.view`, `customers.create`, `customers.edit`, `customers.delete`
- `projects.view`, `projects.manage`
- `tasks.view`, `tasks.create`, `tasks.assign`, `tasks.review`
- `services.view`, `services.manage`
- `employees.view`, `employees.manage`
- `attendance.view`, `attendance.manage`
- `leaves.view`, `leaves.request`, `leaves.approve`
- `suppliers.view`, `suppliers.manage`
- `subscriptions.view`, `subscriptions.manage`
- `whatsapp.view`, `whatsapp.send`
- `notifications.view`
- `settings.view`, `settings.manage`
- `audit.view`
- `roles.manage`

### Financial (guarded)
- `finance.view`  — master gate for any financial screen
- `quotations.view`, `quotations.manage`
- `invoices.view`, `invoices.manage`
- `payments.view`, `payments.manage`
- `expenses.view`, `expenses.manage`
- `accounts.view`, `accounts.manage`      (cash & banks)
- `accounting.view`, `accounting.manage`  (journal, chart of accounts)
- `payroll.view`, `payroll.manage`
- `reports.operational`
- `reports.financial`

## Role → permission bundles (seed defaults)

| Role | Gets |
|------|------|
| **Super Admin** | everything (via Gate::before) |
| **General Manager** | all operational + all financial view + manage most (no roles.manage by default; has settings) |
| **Accountant** | finance.view + quotations/invoices/payments/expenses/accounts/accounting manage + reports.financial + customers.view + suppliers.manage + subscriptions.manage + payroll.view |
| **HR Manager** | employees.manage, attendance.manage, leaves.approve, payroll.manage, reports.operational (payroll is HR-financial) |
| **Project Manager** | customers.view, projects.manage, tasks.* , services.view, reports.operational (NO finance) |
| **Team Leader** | tasks.view/create/assign/review, projects.view, attendance.view, leaves.request |
| **Employee** | dashboard.view, tasks.view, projects.view (own), attendance.view, leaves.request, notifications.view — NO finance.* at all |

## Enforcement layers
1. Route middleware: `->middleware('can:invoices.view')` etc.
2. Policies per model (view/create/update/delete/void...).
3. Livewire components authorize in `mount()`.
4. Query scoping: employees see only their own tasks/projects.
5. API/JSON responses hide financial fields for unauthorized users (resource-level).

The employee layout has **no financial routes at all**; even a hand-typed financial URL is blocked by `can:` middleware + policy, returning 403.

## Sprint 1 & 2 additions
- HR (Sprint 1): `departments.*`, `employees.create|edit|documents`, `attendance.view_own|check_in|check_out|adjust|reports`, `leaves.view_own|create|reject|manage`. Every staff role gets a **self-service** bundle (`attendance.view_own|check_in|check_out`, `leaves.view_own|create|request`).
- Operational (Sprint 2): `customers.manage|archive|attachments`; `services.create|edit|view_financial`; `projects.view_assigned|create|edit|members|attachments`; `tasks.view_own|edit|manage|comment|attachments|checklist`.
- **Semantics**: `*.view` = view everything (managers); `*.view_own` / `projects.view_assigned` = scoped to the employee. `services.view` never exposes prices/costs — that needs `services.view_financial` (enforced in `Service::toArray`). Financial protection is enforced at the backend/query layer, not the UI.
- **Least privilege**: PM manages projects/tasks and sees services without prices; Accountant has finance + service pricing but no project/task admin; HR has HR only; Employee/Team Leader are operational (own tasks/projects, comment/checklist/attachments).
