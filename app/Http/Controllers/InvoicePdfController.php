<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AuditLogger;
use App\Services\InvoicePdfService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a real PDF of an invoice for download. Gated by invoices.print; the
 * download is recorded in the audit trail. The PDF is generated on demand and
 * never persisted to public storage.
 */
class InvoicePdfController extends Controller
{
    public function download(Invoice $invoice, InvoicePdfService $pdf, AuditLogger $audit): Response
    {
        $this->authorize('print', $invoice);

        $filename = $pdf->filename($invoice);

        $audit->log('invoice_pdf_downloaded', $invoice, 'Invoices',
            description: "تنزيل PDF للفاتورة {$invoice->invoice_number}");

        return $pdf->make($invoice)->download($filename);
    }
}
