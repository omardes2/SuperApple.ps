# Payments, Allocation & Exchange Gain/Loss (Sprint 4)

The payment records cash actually received from a customer and settles it against
one or more invoices. **USD is the official receivable currency.** ILS payments
are converted to a USD equivalent using an **independent per-payment exchange
rate** — never the invoice's rate.

## The single-rate design (FIXED)

Each payment carries **one** `exchange_rate` = the USD→ILS rate at the payment
date, used for **both** currencies:

- **ILS payment**: `usd_equivalent = payment_amount ÷ exchange_rate`.
- **USD payment**: `usd_equivalent = payment_amount`; the rate is still recorded
  and used for ILS accounting valuation and the exchange gain/loss.

The rate is required and must be `> 0` to post (even for USD payments, so the
accounting valuation and gain/loss are always well-defined). It is captured to
6 decimals and **locked** the moment the payment is posted.

### Worked examples (mandatory)
- Owes `$3,000`; pays `3,300 ILS` at `3.30` → `usd_equivalent = $1,000` →
  invoice paid `$1,000`, remaining `$2,000`, status **PartiallyPaid**.
- Multi-invoice: `8,250 ILS ÷ 3.30 = $2,500` settles a `$1,000` and a `$1,500`
  invoice in one payment; both become **Paid**.

## Tables

- **payments**: `payment_number` (PAY-YYYY-####, unique, never reused after
  cancel), `customer_id`, `payment_date`, `payment_currency` (USD|ILS),
  `payment_amount` (2dp), `exchange_rate` (6dp, nullable until set),
  `usd_equivalent` (2dp), `payment_method`, `account_id`
  (**nullable, no FK** — reserved for the Sprint 5 cash/bank link),
  `reference_number`, `notes`, `status` (draft|posted|cancelled), `received_by`,
  `posted_at`, `cancelled_at`/`cancelled_by`/`cancellation_reason`,
  `created_by`/`updated_by`. Indexed on customer / payment_date / status.
- **payment_allocations**: `payment_id` (FK cascade), `invoice_id`,
  `allocated_usd` (2dp), plus a full **accounting snapshot** per allocation:
  `invoice_exchange_rate`, `payment_exchange_rate`,
  `invoice_accounting_value_ils`, `payment_accounting_value_ils`,
  `exchange_difference_ils`, `status` (active|reversed),
  `reversed_at`/`reversed_by`/`reversal_reason`.

Enums: `PaymentCurrency` (USD, ILS), `PaymentMethod` (cash, bank_transfer,
cheque, credit_card, online_payment, other), `PaymentStatus`
(draft, posted, cancelled).

## Lifecycle (all via `PaymentService` — status is never set by hand)

1. **createDraft** — records the customer, date, currency, amount, method, and
   computes a provisional `usd_equivalent`. Auto-numbers via
   `DocumentNumberService`.
2. **updateDraft** — Draft only; recomputes `usd_equivalent`.
3. **post(allocations[])** — the single gate. Runs in a **DB transaction** with
   `lockForUpdate` on the payment. Validates: customer exists, date present,
   amount `> 0`, rate `> 0`; recomputes `usd_equivalent` authoritatively; rejects
   over-allocation of the payment (`Σ allocated > usd_equivalent`); then creates
   each allocation (each re-locking its invoice). Sets status **Posted** +
   `posted_at`, then **locks**.
4. **cancel(actor, reason)** — requires a reason. In a transaction, **reverses**
   every active allocation (restoring each invoice's `remaining`/`status`), marks
   the allocation `reversed` (**kept for history — never hard-deleted**), and sets
   the payment **Cancelled**. Posted payments are never edited or deleted;
   corrections are made by cancel + new payment.

## Allocation & over-allocation guards (`PaymentAllocationService`)

Each allocation, under an invoice row lock:
- invoice belongs to the **same customer** as the payment;
- invoice `acceptsAllocation()` — Issued / Sent / PartiallyPaid / (computed)
  Overdue **and** `remaining_usd > 0`; Draft / Cancelled / Paid are rejected;
- `allocated_usd > 0`;
- `allocated_usd ≤ invoice.remaining_usd`.

`applyToInvoice` updates `paid_usd_equivalent` / `remaining_usd` and never lets
remaining go below zero (a `< 0.01 USD` rounding residue is absorbed to `0.00`).
Invoice status is **derived only here**: remaining ≤ 0 → **Paid**; paid > 0 →
**PartiallyPaid**; otherwise back to **Sent** (if it had been sent) or **Issued**
— `sent_at` is preserved.

## Exchange gain / loss (per allocation)

Booked on the **allocated USD portion only**, using both stored rates:

```
invoice_accounting_value_ils = allocated_usd × invoice.exchange_rate
payment_accounting_value_ils = allocated_usd × payment.exchange_rate
exchange_difference_ils      = payment_accounting_value_ils − invoice_accounting_value_ils
```

`+` = **gain**, `−` = **loss**, rounded to 2 ILS. It is **realised** ILS
gain/loss, **never sales revenue**. Because the rates are snapshotted onto the
allocation at post time, later edits to the global exchange-rate table never
change a booked difference.

- Example (gain): `$1,000 × 3.30 − $1,000 × 3.20 = +100 ILS`.
- Example (loss): `$1,000 × 3.20 − $1,000 × 3.30 = −100 ILS`.

## Auto-allocate (oldest first)

`autoAllocatePlan()` suggests spreading the payment's unallocated USD across the
customer's open invoices ordered by **due_date first, then invoice_date**,
filling each up to its remaining and skipping Paid/Cancelled. Any amount left
unallocated becomes **customer credit** (see CUSTOMER_BALANCES.md) — it is never
lost.

## Immutability (multi-layer)

- **Model guard**: `Payment::booted()` throws `PostedPaymentImmutableException`
  if any `LOCKED_FIELDS` (customer, date, currency, amount, exchange_rate,
  usd_equivalent, method) is dirty once the raw original status is not Draft. The
  draft→posted transition is allowed (original status still Draft at that update).
- **Policy**: `PaymentPolicy::update/post` require Draft; `cancel` requires
  not-already-cancelled.
- **Service**: `updateDraft`/`post` assert Draft; `cancel` rejects double-cancel.

## Concurrency & decimal safety

- Posting and cancelling run in a **transaction**; the payment and each invoice
  are re-read with `lockForUpdate`. A failure mid-post rolls back **all**
  allocations and leaves the payment Draft (tested).
- All money math goes through `App\Support\Money` (brick/math, HALF_UP, 2dp
  money / 6dp rates). ILS→USD uses a generous internal scale before final
  rounding.

## Authorization

Route `can:payments.*` + `PaymentPolicy` + component `authorize()`. Permissions:
`payments.view|create|edit|post|cancel|allocate|print`, plus
`customer_statements.view` and `exchange_gain_loss.view`. **Accountant + General
Manager only**; Employee / Project Manager / HR Manager / Team Leader get none.
Financial data is never loaded for unauthorised users.

## UI & print

- **Payments list** (`/admin/payments`): summary cards (collected this month in
  USD and original ILS, unallocated credit, posted/cancelled counts), filters,
  table; “+ دفعة” creates a draft and opens it.
- **Payment page** (`/admin/payments/{payment}`): draft form (customer/date/
  currency/amount/rate → live USD-equivalent preview), open-invoice allocation
  editor with an **Auto-Allocate (oldest)** button, post; posted view shows the
  allocations table with the exchange difference; cancel-with-reason modal.
- **Receipt** (`/admin/payments/{payment}/receipt`): A4/A5 RTL print
  (`resources/views/print/receipt.blade.php`). Shows amount received, USD
  equivalent, and settled invoices only — **never** cost, margin, or internal
  notes.
- **Exchange Gain/Loss report** (`/admin/reports/exchange-gain-loss`): date +
  customer filters, gain/loss/net cards, per-allocation table.

## Out of scope for Sprint 4 (deferred)

No cashbox/bank ledger, GL/journal entries, expenses, suppliers, payroll posting,
or WhatsApp. `payments.account_id` is nullable and unlinked, reserved for the
Sprint 5 cash/bank module.
