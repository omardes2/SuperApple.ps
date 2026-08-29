# Invoices (Sprint 3)

The invoice is the primary financial document. USD is the official receivable currency; the ILS value is an accounting snapshot taken at issue.

## Tables
- **invoices**: `invoice_number` (INV-YYYY-####, never reused even if cancelled), `quotation_id` (nullable, **unique** → a quotation backs at most one invoice), customer, project, `invoice_date`, `due_date`, `currency` (USD), `subtotal_usd`/`discount_usd`/`tax_usd`/`total_usd`, `exchange_rate` (6dp, null until set), `total_ils_at_issue`, `paid_usd_equivalent`, `remaining_usd`, `status`, `issued_at`/`sent_at`/`cancelled_at`, `cancellation_reason`/`cancelled_by`, `customer_snapshot` (json), notes/terms.
- **invoice_items**: independent snapshot — `service_id` (reference only), `item_name`, description, quantity, `unit_price_usd` (4dp), `line_subtotal_usd`, `discount_type`/`discount_value`/`discount_usd`, `tax_rate`/`tax_usd`, `line_total_usd`, sort.
- Enum `InvoiceStatus`: Draft / Issued / Sent / PartiallyPaid / Paid / Overdue / Cancelled (PartiallyPaid & Paid are used from Sprint 4).

## Lifecycle (all via `InvoiceService` — status is never set by hand)
1. **createDraft** — items, suggested exchange rate (latest ≤ invoice_date), default due date (`finance.default_invoice_due_days`), terms from settings. Totals recomputed on the backend.
2. **updateDraft** — Draft only; edit customer/project/dates/exchange rate/items; totals recomputed.
3. **issue** — the single gate. Validates: customer active, ≥1 item, invoice_date present, currency USD, exchange_rate > 0. Recomputes totals from item snapshots, sets `total_ils_at_issue = total_usd × rate`, `paid=0`, `remaining=total_usd`, re-captures `customer_snapshot`, sets status Issued + `issued_at`, then **locks**.
4. **send** — Issued → Sent.
5. **cancel(reason)** — requires a reason; keeps the record and number; never returns to Draft; never hard-deleted.

## Immutability (multi-layer)
- **Model guard**: `Invoice::booted()` throws `IssuedInvoiceImmutableException` if any `LOCKED_FIELDS` (customer, project, dates, currency, all money fields, exchange_rate, total_ils_at_issue, customer_snapshot, quotation_id) is dirty once the raw original status is not Draft. The draft→issued transition is allowed because the original status is still Draft at that update.
- **Item guard**: `InvoiceItem::booted()` blocks save/delete when its invoice is not Draft.
- **Policy**: `InvoicePolicy::update/issue` require Draft; `cancel` requires not-already-cancelled.
- **Service**: `updateDraft`/`issue` assert Draft.

## Authorization
Route `can:invoices.*` + `InvoicePolicy` + component `authorize()`. Permissions: `invoices.view|create|edit|issue|send|cancel|print`. Employees have none → 403/redirect; PM/HR are not granted invoices; Accountant + GM have the full lifecycle.

## Print
`GET /admin/invoices/{invoice}/print` → A4 RTL print-ready HTML (`resources/views/print/invoice.blade.php`). Shows company info, customer snapshot, items, USD totals, exchange rate + ILS equivalent, terms/footer. **Never** shows service cost, margins, or internal notes. PDF export is a deferred nice-to-have; print-ready HTML (browser print) is the mandatory deliverable.

## Outstanding / Overdue
Outstanding = Σ `remaining_usd` of issued (non-cancelled) invoices. Overdue is computed (`isOverdue()` / `effectiveStatus()`), never stored — no scheduler.
