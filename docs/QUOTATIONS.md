# Quotations (Sprint 3)

A quotation is a commercial document carrying prices — it is financial data; ordinary employees never see it. Currency is USD.

## Tables
- **quotations**: `quotation_number` (QUO-YYYY-####), customer, project (nullable), `quotation_date`, `valid_until`, currency (USD), `subtotal_usd`/`discount_usd`/`tax_usd`/`total_usd`, `status`, notes/terms, `customer_snapshot` (json, captured at send), `sent_at`/`accepted_at`/`rejected_at`/`cancelled_at`, `accepted_by`, `revision_of` (lineage), `converted_invoice_id`.
- **quotation_items**: same snapshot shape as invoice items (service_id reference only; name/price/tax copied).
- Enum `QuotationStatus`: Draft / Sent / Accepted / Rejected / Expired / Cancelled. **Expired** is computed on the fly (`effectiveStatus()`) from `valid_until`, never stored.

## Workflow (`QuotationService`)
```
Draft ──send──> Sent ──accept──> Accepted ──convert──> Invoice (draft)
  │               │  └─reject──> Rejected
  └─cancel        └─cancel / duplicateAsRevision
```
- **Draft** is the only editable state (`updateDraft`); totals recomputed on the backend by `DocumentCalculator`.
- After **Sent**, the document is frozen. To change it, **duplicateAsRevision** creates a new Draft copying the items with `revision_of` set — the sent document is never silently mutated.
- **accept** records `accepted_at`/`accepted_by`. Only an **Accepted** quotation can convert.

## Service / item snapshot
When a line references a catalog service, its name/price/tax are copied into the item at document time (`BuildsLineItems`). Later catalog price changes never alter existing quotations. The financial user may override the unit price for a specific document without touching the catalog default.

## Conversion to invoice (`QuotationToInvoiceService`)
- Only an **Accepted**, not-yet-converted quotation converts. The row is `lockForUpdate()`-ed inside a transaction and the unique `invoices.quotation_id` index guarantees **exactly one** invoice per quotation (idempotent, double-click safe).
- The invoice copies customer/project/item snapshots/prices/discounts/taxes/notes/terms and becomes an **independent** Draft invoice — later quotation edits (via revision) never mutate it.

## Authorization
`can:quotations.*` + `QuotationPolicy` + component `authorize()`. Permissions: `quotations.view|create|edit|send|accept|reject|cancel|convert|print`. Employees/PM/HR have none; Accountant + GM have the full lifecycle.

## Print
`GET /admin/quotations/{quotation}/print` → A4 RTL print view (`resources/views/print/quotation.blade.php`).
