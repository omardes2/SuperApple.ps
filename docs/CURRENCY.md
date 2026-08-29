# Currency & Financial Business Rules (FIXED — do not change)

These decisions are constants of the system.

## Base rules
1. Base **accounting** currency = **ILS** (Israeli Shekel).
2. Default **invoice** currency to customers = **USD**.
3. Official **customer balance** currency = **USD**.
4. An invoice records the USD→ILS exchange rate **at issue time**.
5. Invoice shows USD total and its ILS equivalent at issue.
6. Invoice exchange rate is **locked** after issue — never changes even if USD moves.
7. Customer may pay in **USD or ILS**.
8. Each ILS payment records its own exchange rate at payment time.
9. Each payment is converted to a **USD equivalent**.
10. Partial payment reduces the USD debt.
11. Exchange-rate difference is booked as **Exchange Gain / Loss** (never Sales Revenue).
12–20. Operational/employee/HR/whatsapp/audit rules (see ARCHITECTURE.md & PERMISSIONS.md).

## Invoice fields
```
currency            = 'USD'
subtotal_usd
discount_usd
tax_usd
total_usd
exchange_rate       (USD->ILS, locked at issue)
total_ils_at_issue  = total_usd * exchange_rate
paid_usd_equivalent
remaining_usd
status
```

## Payment fields
```
invoice_id
customer_id
payment_date
payment_currency    (ILS | USD)
payment_amount      (in payment_currency)
exchange_rate       (USD->ILS at payment date; 1 when USD)
usd_equivalent      = payment_currency == USD ? payment_amount : payment_amount / exchange_rate
payment_method
account_id          (cashbox or bank)
reference_number
notes
created_by
```

## Conversion math
- USD equivalent of an ILS payment = `ILS_amount / exchange_rate`.
  - Example: owes $3,000, pays 3,300 ILS at 3.30 → USD equiv = $1,000 → Paid $1,000, Remaining $2,000.
- An invoice can **never become overpaid**: allocation is capped at `remaining_usd`.

## Exchange Gain / Loss
- Accounting value of a receivable is fixed at invoice's issue rate (ILS).
- When cash is received, the ILS actually received may differ from the ILS booked for that USD portion.
- Difference is posted to **Exchange Gain** or **Exchange Loss**, NOT revenue.
- Example: Invoice $10,000 @ 3.25 → booked 32,500 ILS. Collected fully at 3.35 → 33,500 ILS received. Diff 1,000 ILS = Exchange Gain.

## Exchange rates module
- `exchange_rates` (date, usd_to_ils). Invoice/payment forms suggest the latest rate; user can override before saving. After issue/save the stored rate is immutable.

## Customer statement
- Official running balance in USD (invoices +, allocated payments −).
- May also show an *estimated* ILS value at today's rate — clearly marked estimate, not the official balance.

## Rounding
- Monetary values stored as `decimal(15,2)`; exchange rates `decimal(12,6)`.
- USD equivalents rounded to 2 decimals at allocation; ILS-at-issue rounded to 2 decimals.

---

## Sprint 3 — Invoice & Exchange Rate rules (implemented)

### Fixed rules
- **Base accounting currency = ILS.** **Invoice currency = USD.** **Invoice/customer balance = USD** (official receivable).
- An invoice records the USD→ILS **exchange rate at issue**; it is **locked** — never editable afterward (enforced by `Invoice::LOCKED_FIELDS` + `IssuedInvoiceImmutableException`, and by `InvoicePolicy::update`).
- **ILS equivalent** `total_ils_at_issue = total_usd × exchange_rate` is a **snapshot at issue**; later edits to the exchange-rate table never change it.
- A future **payment exchange rate is INDEPENDENT** — Sprint 4 will record `payment.exchange_rate` at payment time and must NOT reuse `invoice.exchange_rate` to convert an ILS payment.
- Payment currency later may be USD or ILS; exchange gain/loss is computed when payments/accounting arrive (Sprints 4–5). No payment logic exists in Sprint 3.

### Decimal-safe math (`App\Support\Money`, `App\Support\DocumentCalculator`)
- All money math uses **brick/math BigDecimal** — never native float.
- **Rounding: HALF_UP** everywhere. Money scale = 2dp (USD & ILS). Exchange-rate scale = 6dp. Unit price stored to 4dp; each line's amounts are rounded to 2dp, and document totals are the **sum of the rounded line amounts** (so lines always add up to the totals).
- Per line: `gross = qty × unit` → `discount` (percentage `gross×v/100`, or fixed; capped at gross) → `taxable = gross − discount` → `tax = taxable × rate/100` → `line_total = taxable + tax`.
- Document: `subtotal = Σ gross`, `discount = Σ line discount`, `tax = Σ line tax`, `total = Σ line_total = subtotal − discount + tax`. **Line-level discounts only** (invoice-level discount intentionally omitted to avoid double-counting).

### Worked example (mandatory)
Invoice subtotal `$2,000`, tax 0, rate `3.28` → stored: `total_usd=2000.00`, `exchange_rate=3.280000`, `total_ils_at_issue=6560.00`, `paid_usd_equivalent=0.00`, `remaining_usd=2000.00`. Correcting the rate table to `3.35` afterward leaves the invoice at `3.280000` / `6560.00`.

### Overdue
Computed, never stored: `due_date < today` AND `remaining_usd > 0` AND status ∉ {Paid, Cancelled, Draft}. Surfaced via `Invoice::isOverdue()` / `effectiveStatus()`. No scheduler required.

---

## Sprint 4 — Payments, allocation & exchange gain/loss (implemented)

Full detail in **PAYMENTS.md** and **CUSTOMER_BALANCES.md**. Key fixed rules as realised:

- **One `exchange_rate` per payment** = USD→ILS at payment date, used for BOTH currencies. ILS → `usd_equivalent = amount ÷ rate`; USD → `usd_equivalent = amount` (rate still stored for accounting valuation and gain/loss). The rate is **required and > 0** to post, and **locked** after posting (`Payment::LOCKED_FIELDS` + `PostedPaymentImmutableException`).
- A later payment **never** reuses `invoice.exchange_rate` to convert ILS — each payment has its own independent rate.
- **Exchange difference per allocation** = `allocated_usd × payment_rate − allocated_usd × invoice_rate` (+ gain / − loss), on the allocated portion only, rounded to 2 ILS. It is **realised exchange gain/loss, never sales revenue**, and is snapshotted onto the allocation so later rate-table edits don't change it.
- **Over-allocation is impossible**: `allocated ≤ invoice.remaining` AND `Σ allocations ≤ payment.usd_equivalent`; `remaining_usd` never goes negative (a `< 0.01 USD` residue is absorbed to 0).
- **Invoice status is derived only by the service** (Paid/PartiallyPaid/Issued/Sent, `sent_at` preserved). Draft/Cancelled/Paid invoices reject allocation.
- **Cancel** reverses allocations, restores invoice remaining/status, and keeps the allocation history marked `reversed` (no hard delete). Payment numbers (PAY-YYYY-####) are never reused after cancel.
- **Customer balance** = three distinct USD figures — Outstanding, Unallocated credit, Net — never conflated; unallocated payment amount is kept as customer credit. An ILS value may be shown as a clearly-marked estimate only.
- Concurrency via DB transaction + `lockForUpdate`; all math via `App\Support\Money` (brick/math, HALF_UP).

### Payment fields (as built)
```
payment_number      PAY-YYYY-#### (unique)
customer_id
payment_date
payment_currency    (USD | ILS)
payment_amount      (in payment_currency, 2dp)
exchange_rate       (USD->ILS at payment date, 6dp; required to post)
usd_equivalent      = currency==USD ? amount : amount / exchange_rate
payment_method      (cash|bank_transfer|cheque|credit_card|online_payment|other)
account_id          (nullable, NO FK — reserved for Sprint 5 cash/bank)
reference_number, notes, status(draft|posted|cancelled), received_by
```
`account_id` is intentionally unlinked in Sprint 4 — no cashbox/bank/GL yet.

---

## Sprint 5 — GL posting (implemented)
Financial events now post double-entry journals in **ILS** (base ledger currency) while invoices/receivables stay USD. Invoice issue books AR/Revenue/Tax at the invoice's frozen rate; customer payments book cash, settle AR at the invoice rate, and post the FX difference to Exchange Gain/Loss (never revenue); overpayments go to Customer Credits (a liability). Full journal examples and rules in **ACCOUNTING.md**.
