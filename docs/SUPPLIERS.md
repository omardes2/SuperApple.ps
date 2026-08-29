# Suppliers, Bills & Payments (Sprint 5)

Suppliers are vendors we owe money to (accounts payable). Unlike a paid expense,
a **supplier bill** is booked now and paid later.

## Suppliers
`supplier_number` (SUP-#####), name, contact, phone, whatsapp, address,
tax_number, type, notes, is_active. No email (consistent with customers). CRUD
via `SupplierService`; attachments supported via the shared attachment system.

## Supplier bills (`supplier_bills` + `supplier_bill_items`)
A vendor bill in its **original currency** plus an ILS accounting value at the
bill rate. Fields: `bill_number` (BILL-YYYY-####), supplier, project?, dates,
`currency`, `subtotal`/`tax`/`total` (original), `exchange_rate?`, `total_ils`,
`paid_original`, `remaining_original`, status
(draft/posted/partially_paid/paid/cancelled).

Posting books AP (`SupplierBillService::post`):
```
Dr <item expense accounts>   total_ils
   Cr 2100 Accounts Payable       total_ils
```
Items may each carry an `expense_account_id` (else `default_expense`). Financial
fields are frozen once posted. A bill with active payment allocations cannot be
cancelled; cancelling a posted bill reverses its journal.

## Supplier payments (`supplier_payments` + `supplier_payment_allocations`)
Payments settle bills, allocated to bills of the **same currency** and **fully
allocated** (no supplier advances or implicit conversion in Sprint 5). Each
allocation snapshots `bill_accounting_value_ils` (at bill rate),
`payment_accounting_value_ils` (at payment rate) and their difference.

Posting (`SupplierPaymentService::post`):
```
Dr 2100 Accounts Payable     Σ bill_accounting (settled at bill rate)
[Dr 5950 Exchange Loss / Cr 4900 Exchange Gain]   per FX difference
   Cr <financial account GL>    Σ payment_accounting (actual paid, ILS value)
```
On a USD bill paid at a different rate, the difference is a realised FX gain
(paid less ILS than booked) or loss. Cancelling reverses the allocations
(restoring each bill's remaining/status) and reverses the GL journal.

## Supplier balance
`SupplierBalanceService`: total billed, total paid, and **outstanding** (Σ open
bills' remaining × bill rate, in ILS) — which reconciles to the AP GL account.
Supplier profile at `/admin/suppliers/{id}` (overview / bills / payments /
expenses / statement / activity).

## Authorization
`suppliers.view|create|edit|manage`, `supplier_bills.view|create|edit|post|cancel`,
`supplier_payments.view|create|post|cancel` — Accountant + GM only. PM/HR/
employees get **no** supplier visibility.
