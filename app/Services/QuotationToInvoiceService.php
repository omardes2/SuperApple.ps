<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Invoice;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Converts an accepted quotation into a Draft invoice — exactly once. The
 * invoice becomes an independent historical document (later quotation edits
 * never touch it). Double-conversion is blocked by a lock + the unique
 * invoices.quotation_id index.
 */
class QuotationToInvoiceService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
    ) {}

    public function convert(Quotation $quotation): Invoice
    {
        return DB::transaction(function () use ($quotation) {
            // Lock the row so two concurrent conversions can't both proceed.
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::Accepted) {
                throw new RuntimeException('يمكن تحويل العروض المقبولة فقط إلى فاتورة.');
            }

            if ($quotation->converted_invoice_id) {
                throw new RuntimeException('تم تحويل هذا العرض إلى فاتورة مسبقاً.');
            }

            // Copy item snapshots into fresh invoice line inputs.
            $lines = $quotation->items->map(fn ($item) => [
                'service_id' => $item->service_id,
                'item_name' => $item->item_name,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_usd' => $item->unit_price_usd,
                'discount_type' => $item->discount_type?->value,
                'discount_value' => $item->discount_value,
                'tax_rate' => $item->tax_rate,
            ])->all();

            $invoice = $this->invoices->createDraft([
                'quotation_id' => $quotation->id,
                'customer_id' => $quotation->customer_id,
                'project_id' => $quotation->project_id,
                'invoice_date' => now()->toDateString(),
                'notes' => $quotation->notes,
                'terms' => $quotation->terms,
            ], $lines);

            $quotation->update(['converted_invoice_id' => $invoice->id, 'updated_by' => $invoice->updated_by]);

            $this->audit->log('quotation_converted', $quotation, 'Quotations',
                new: ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
                description: "تحويل عرض السعر {$quotation->quotation_number} إلى فاتورة {$invoice->invoice_number}");

            return $invoice;
        });
    }
}
