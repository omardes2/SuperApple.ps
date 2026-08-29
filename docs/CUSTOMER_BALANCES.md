# Customer Balances & Statement (Sprint 4)

The customer's official balance is **always in USD**. Three figures are kept
distinct and **never conflated** (`App\Services\CustomerBalanceService`):

| Figure | Definition |
|--------|------------|
| **Outstanding** | `Σ remaining_usd` of the customer's invoices that are **not** Draft and **not** Cancelled. |
| **Unallocated credit** | `Σ usd_equivalent` of **posted** payments − `Σ allocated_usd` of their **active** allocations. Money paid but not yet applied to an invoice. |
| **Net balance** | `Outstanding − Unallocated credit`. Negative = the customer is **in credit**. |

These are surfaced as three separate cards on the customer profile and the
statement page — the net figure is never shown alone.

### Worked example
Invoice `$1,500`; customer pays `$2,000`, allocates `$1,500`:
`Outstanding = $0.00`, `Unallocated credit = $500.00`, `Net = −$500.00`.

## Estimated ILS value (informational only)

`estimatedOutstandingIls()` multiplies the net USD balance by today's suggested
rate. It is **clearly marked as an estimate** and is **never** the official
balance — the official receivable is USD.

## Customer statement (`CustomerStatementService`)

A chronological USD ledger with a running balance:

- **Invoices** are debits (`total_usd`), **payments** are credits
  (`usd_equivalent`).
- Rows are sorted by date, then invoices before payments on the same day.
- Running balance = `previous − credit + debit`.
- A **multi-invoice payment appears once** (its `usd_equivalent`), so it is never
  double-counted.
- **Draft/Cancelled invoices and non-posted (draft/cancelled) payments are
  excluded** — a cancelled payment leaves no credit row and its invoices return
  to outstanding.

Rendered at `/admin/customers/{customer}/statement` (permission
`customer_statements.view`) with a print button; the three balance cards head the
page, and the closing balance is shown in the table footer.

## Where balances appear

- **Customer profile** (`/admin/customers/{customer}`): the three balance cards
  on the overview tab (for `payments.view` users), a **Payments** tab listing the
  customer's payments, and a link to the full statement.
- **Invoice page**: a payments panel showing total / paid / remaining and the
  invoice's allocations, plus a **Record Payment** button (prefills a draft for
  the invoice).
- **Admin dashboard**: collected-this-month, outstanding, unallocated credit, and
  net realised exchange difference cards — **finance users only**, never
  employees.

## Authorization

All balance/statement reads require `payments.view` /
`customer_statements.view` — Accountant + General Manager only. Nothing is
queried or rendered for unauthorised users.
