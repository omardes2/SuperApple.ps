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
