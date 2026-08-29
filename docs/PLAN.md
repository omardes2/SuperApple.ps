# Implementation Plan

Status legend: ✅ done · 🚧 in progress · ⬜ pending

## Sprint 0 — Foundation ✅ (30 tests green)
- ✅ Laravel 13, PHP 8.4, Livewire 4, spatie/laravel-permission, Tailwind 4 RTL
- ✅ Architecture / DB / currency / permissions docs
- ✅ Enums (Currency, RoleName)
- ✅ `settings`, `audit_logs`, `document_sequences` migrations + models
- ✅ Extend `users` (phone, employee_id, is_active, locale)
- ✅ Permissions + roles seeder (full matrix in `App\Support\Permissions`)
- ✅ `Auditable` trait + `AuditLogger` service (strips secrets)
- ✅ `Settings` service (cached key/value, typed)
- ✅ `DocumentNumberService` (atomic auto numbering)
- ✅ Gate::before super-admin bypass
- ✅ Auth (login/logout via Livewire), role-based redirect
- ✅ Admin RTL layout + Employee RTL layout, permission-aware sidebar (`AdminNavigation`)
- ✅ `admin.area` middleware keeps employees out of back-office
- ✅ Settings Livewire page, Audit Log Livewire page
- ✅ Seeders: 7 role users + settings + roles/permissions
- ✅ Tests: auth, employee/PM cannot reach financial data, audit writes (no secret leak), settings save+audit, numbering, page rendering
- ✅ migrate + test green + pint clean

## Sprint 1 — HR ✅ (39 tests green; 69 total)
- ✅ Departments (CRUD, manager, active toggle, delete guard when employees exist) + `DepartmentService`
- ✅ Employees module separate from User auth, bi-directional link, profile with tabs, documents; `EmployeeService` (auto number, circular-manager prevention). No salary/financial fields on the employee record.
- ✅ Attendance: check-in/out (server clock, no double in/out), grace/late/worked/overtime maths, admin dashboard + adjustments, employee self-service; `AttendanceService`
- ✅ Leaves: types, request workflow (submit/approve/reject/cancel/reverse), working-day counting excluding weekend, overlap protection, attendance sync on approval; `LeaveService`
- ✅ Enums: EmploymentStatus, EmploymentType, AttendanceStatus, AttendanceSource, LeaveStatus
- ✅ Granular permissions + self-service bundle; employee/admin dashboards updated with HR cards
- ✅ Database notifications (leave submitted/approved/rejected, attendance adjusted)
- ✅ Seeders: 8 departments, 5 leave types, 10 employees linked to demo users, ~2 weeks attendance, sample leaves
- ✅ Docs: `docs/HR.md`
- ✅ Full suite green + pint clean; no Sprint 0 regression

## Sprint 2 — Operational core ✅ (48 tests green; 117 total)
- ✅ Customers (CRM) with categories, sources, statuses, profile tabs, attachments, archive-not-delete; `CustomerService`. No email field anywhere.
- ✅ Services catalog with **backend financial-field protection** (`services.view_financial`); price/cost change auditing; `ServiceCatalogService`.
- ✅ Projects with members (dedup), derived progress, profile (overview/tasks/team/files/activity), cancel-not-delete; `ProjectService`.
- ✅ Tasks: full workflow (`TaskWorkflowService`), assignees, comments, checklist, status history, tags, unified attachments; customer/project consistency; `TaskService`.
- ✅ Enums: Priority, CustomerStatus, CustomerSource, ServiceType, ProjectStatus, TaskStatus.
- ✅ Real query-level visibility scoping (`visibleTo`) for customers/projects/tasks; employees can't enumerate others' data or open unrelated records (403).
- ✅ Employee experience: My Tasks (filters), My Projects, task detail with workflow actions; admin experience: full index + detail pages.
- ✅ Dashboards: operational cards (admin) + real task/project data (employee, placeholders removed).
- ✅ Notifications: task assigned/submitted/status-changed, project member added.
- ✅ Seeders: 10 customers, 16 services, 6 projects (+members), 34 tasks (+comments/checklists/status history).
- ✅ Docs: `docs/OPERATIONS.md`. Full suite green + pint clean + build; no Sprint 0/1 regression.

## Sprint 3 — Billing ✅ (32 tests green; 149 total)
- ✅ Decimal-safe money engine on brick/math (`Money`, `DocumentCalculator`) — HALF_UP, no floats.
- ✅ Exchange rates (USD→ILS, one rate/day, update-not-duplicate, audited; suggested rate for documents).
- ✅ Quotations + items with service/price snapshots; workflow (draft/send/accept/reject/cancel/duplicate-revision); `QuotationService`.
- ✅ Invoices + items; `InvoiceService::issue()` as the single gate (validate → recompute → lock ILS snapshot → init payment fields → immutable). Multi-layer immutability (model guard + item guard + policy + service).
- ✅ Quotation→Invoice conversion (accepted-only, transaction + unique index = exactly once); `QuotationToInvoiceService`.
- ✅ Policies (Invoice/Quotation/ExchangeRate) + granular financial permissions; employees/PM/HR blocked, Accountant/GM full.
- ✅ Admin UIs (exchange rates, quotation editor + workflow, invoice editor + issue/cancel, customer financial tabs) + A4 RTL print views for invoice & quotation.
- ✅ Enums: Priority-style statuses (QuotationStatus, InvoiceStatus, ExchangeRateSource, DiscountType).
- ✅ Seeders: 6 demo exchange rates, 10 quotations, 8 invoices (no fake payments — paid=0/remaining=total).
- ✅ Docs: CURRENCY.md (updated), QUOTATIONS.md, INVOICES.md. Full suite green + pint clean + build; no Sprint 0/1/2 regression.
- ⛔ Not started (later sprints): payments, partial payments, exchange gain/loss, cash/bank, journal entries, expenses.

## Sprint 4 — Payments & collection ✅ (39 tests green; 188 total)
- ✅ Payments engine on `PaymentService` (createDraft/updateDraft/post/cancel/autoAllocatePlan) — atomic post/cancel with DB transaction + `lockForUpdate`. Single independent `exchange_rate` per payment for both currencies.
- ✅ `PaymentAllocationService` — same-customer + `acceptsAllocation` + positive + `≤ remaining` guards, per-allocation accounting snapshot, **exchange gain/loss** on the allocated portion, reversal that restores invoice remaining/status and keeps history (`reversed`, never deleted).
- ✅ Derived invoice status (Paid/PartiallyPaid/Issued/Sent, `sent_at` preserved); remaining never negative (`< 0.01 USD` tolerance).
- ✅ `CustomerBalanceService` (Outstanding / Unallocated credit / Net, three distinct USD figures + estimated ILS) and `CustomerStatementService` (USD ledger, multi-invoice payment counted once, cancelled excluded).
- ✅ Immutability: `Payment::LOCKED_FIELDS` + `PostedPaymentImmutableException`; `PaymentPolicy`; granular permissions (`payments.view|create|edit|post|cancel|allocate|print`, `customer_statements.view`, `exchange_gain_loss.view`) — Accountant + GM only; Employee/PM/HR/TeamLeader none.
- ✅ UI: payments list + summary cards, new-payment flow with live USD preview + allocation editor + **Auto-Allocate (oldest)**, cancel-with-reason, A4/A5 RTL **receipt** (no cost/margin/internal notes), customer statement, exchange gain/loss report, customer Payments tab + balance cards, invoice payments panel + Record Payment, finance dashboard cards (not for employees).
- ✅ `account_id` nullable/unlinked (reserved for Sprint 5). No cashbox/bank/GL/expenses/suppliers/payroll/WhatsApp built.
- ✅ Seeder: balanced demo payments (partial ILS, full USD, over-paid credit, cancelled+reversed). Docs: PAYMENTS.md, CUSTOMER_BALANCES.md (new); CURRENCY.md, INVOICES.md, DATABASE.md, PERMISSIONS.md, PLAN.md (updated).
- ✅ Tests: core math/allocation/gain-loss (16), reversal/immutability (6), security (7), integrity/concurrency/auto-allocate/statement (10), smoke render (6). Full suite green + pint clean + build; no Sprint 0–3 regression.
- ⛔ Not started (later sprints): cash/bank, journal entries, expenses, suppliers, payroll, subscriptions, WhatsApp.

## Sprint 5 — Accounting, expenses, suppliers, cash & banks ✅ (75 tests green)
- ✅ Double-entry engine (`AccountingService`) — balanced ILS journals, source idempotency, immutable posted entries, reversal; system-account resolution by stable key.
- ✅ `LedgerPostingService` builds the correct journal for each document; integrated (atomic) into invoice issue/cancel, payment post/cancel, expenses, supplier bills/payments, opening balances, transfers.
- ✅ Chart of accounts + `system_accounts` map; `ChartOfAccountsSeeder`. Financial accounts with GL-derived balances + opening-balance journals; same-currency transfers.
- ✅ Expenses (draft→approved→posted→cancelled) with category GL accounts; suppliers + vendor bills (AP accrual) + supplier payments with FX gain/loss; supplier balances.
- ✅ Reports: Trial Balance, General Ledger, Profit & Loss, Balance Sheet, Reconciliation (AR/AP/Cash all tie to GL). `accounting:backfill` command (idempotent, `--dry-run`) for historical documents.
- ✅ Permissions (chart_accounts.*, journals.*, financial_accounts.*, expenses.*, suppliers.*, supplier_bills.*, supplier_payments.*, reports.gl/trial_balance/profit_loss/balance_sheet/reconciliation) + Policies — Accountant + GM only; UI + navigation + dashboard cards (finance-only).
- ✅ Docs: ACCOUNTING.md, CHART_OF_ACCOUNTS.md, EXPENSES.md, SUPPLIERS.md, CASH_BANKS.md (new); CURRENCY/INVOICES/PAYMENTS/DATABASE/PERMISSIONS/ARCHITECTURE/PLAN (updated).
- ✅ Tests: double-entry, invoice/payment/expense/supplier accounting, invoice cancellation, financial accounts/transfers, reports, reconciliation, security, backfill, smoke — full suite green; no Sprint 0–4 regression.
- ⛔ Not started (Sprint 6+): payroll posting/salary payable/employee loans, WhatsApp reminders, recurring-subscription accounting, VAT filing, inventory.

## Sprint 6 — Payroll, salaries, advances ✅ (70 tests green)
- ✅ Effective-dated salary profiles (off the employees table), salary adjustments (earnings/deductions, one-time + recurring), employee advances/loans.
- ✅ `PayrollCalculator` (attendance/leave integration, absence/late/unpaid-leave deductions from settings, overtime, adjustments, advance recovery capped so net ≥ 0, full calculation snapshot) — pure, never mutates source.
- ✅ `PayrollService` workflow (create → calculate → approve → post → paid), snapshots frozen at approval, posted runs immutable, reversal; `PayrollPaymentService` (partial salary payments, reversal); `EmployeeAdvanceService` (pay/recover/cancel with GL).
- ✅ GL integration via `LedgerPostingService` (Dr Salary Expense / Cr Employee Advances / Cr other withholdings / Cr Salary Payable; salary payment Dr Salary Payable / Cr Cash; advance payment Dr Employee Advances / Cr Cash) — atomic, source-idempotent, reversible. New system accounts: 1400 Employee Advances, 2400 Salary Payable, 2500 Other Payroll Deductions; 5200 Salary Expense mapped.
- ✅ Two new reconciliations (Salary Payable, Employee Advances); payroll flows into Trial Balance / P&L / Balance Sheet with no duplicate logic. Payroll reports (summary/by-dept/outstanding/advances/payments).
- ✅ Permissions + Policies (PayrollRun/PayrollItem/SalaryProfile/SalaryAdjustment/EmployeeAdvance/PayrollPayment) with separation of duties (HR prepares, Accountant posts/pays). Payslip privacy (own payslip only). UI + navigation + printable A4 payslip + employee "My Payslips".
- ✅ Seeder (salary profiles, advance, posted payroll run, salary payments); seed integrity: TB + BS balanced, all 5 reconciliations pass, no negative payroll/advance balances.
- ✅ Docs: PAYROLL.md, SALARIES.md, ADVANCES.md (new); ACCOUNTING/CHART_OF_ACCOUNTS/DATABASE/PERMISSIONS/ARCHITECTURE/PLAN (updated). Full suite green; no Sprint 0–5 regression.
- ⛔ Not started (Sprint 7+): subscriptions, recurring invoices, WhatsApp reminders.

## Sprint 7 — Subscriptions, Recurring Invoices & WhatsApp ✅ (84 tests green)
- ✅ Enums (SubscriptionStatus, BillingCycle, WhatsAppMessageStatus, ReminderTimingType)
- ✅ Migrations: subscriptions, subscription_items, subscription_billings, invoices.subscription_id, whatsapp_templates, whatsapp_messages, payment_reminder_rules, payment_reminder_logs
- ✅ Models + relations (Customer/Invoice/Payment ↔ subscriptions/whatsapp)
- ✅ `SubscriptionService` (lifecycle), `SubscriptionBillingService` (recurring invoices via `InvoiceService`, idempotent, per-subscription txn, auto-issue with rate check + notify), `SubscriptionMetricsService` (MRR/ARR)
- ✅ WhatsApp: `WhatsAppProvider` contract + Null/Log/Fake drivers, provider binding from settings, `WhatsAppService`, `WhatsAppTemplateService`, `TemplateRenderer` (strict), `PhoneNormalizer`
- ✅ `PaymentReminderService` (manual + rule-driven; ILS estimate at latest rate)
- ✅ `SendWhatsAppMessageJob` (retry/backoff, after-commit dispatch, financial isolation)
- ✅ Commands `subscriptions:bill` + `payments:send-reminders` (--date/--dry-run/--subscription) + scheduler
- ✅ Notifications (auto-issue failed, invoice failed, whatsapp failed, expiring soon)
- ✅ Policies (Subscription/WhatsAppMessage/WhatsAppTemplate/PaymentReminderRule) + permissions + role bundles
- ✅ Livewire UI (subscriptions index/show, whatsapp dashboard/templates/reminders, customer profile tabs + reminder modal, invoice whatsapp history + subscription link, dashboard cards) + routes + nav
- ✅ Seeders (WhatsApp templates + reminder rules, subscriptions demo with recurring-invoice history; GL/TB balanced, AR reconciles, 0 duplicate periods)
- ✅ Docs: SUBSCRIPTIONS, RECURRING_INVOICES, WHATSAPP, PAYMENT_REMINDERS + updates
- ✅ Pint clean, npm build, no regression (Sprint 0–7 green)

## Sprint 8
See ARCHITECTURE.md §8 and DATABASE.md. Executed only after the prior sprint's tests pass.

## Definition of Done per sprint
1. Migrations run clean on fresh DB.
2. Seeders produce demo data.
3. `php artisan test` green.
4. `npm run build` succeeds (asset pipeline).
5. Git commit with clear message, pushed to `claude/creative-agency-erp-crm-2j7oz8`.
