<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\CompanyProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an invoice to a real (vector) PDF from the SAME Blade template used by
 * the on-screen print view — no screenshots, no duplicated financial maths. All
 * figures come from the invoice model exactly as the print view reads them.
 *
 * PDFs are produced on demand and never written to public storage. WhatsApp
 * delivery uses a short-lived private temp file that the caller deletes.
 */
class InvoicePdfService
{
    public function __construct(private readonly Settings $settings) {}

    /** The stable, human-readable file name for an invoice PDF. */
    public function filename(Invoice $invoice): string
    {
        return 'Invoice-'.$invoice->invoice_number.'.pdf';
    }

    /** Build the dompdf instance for an invoice (A4, RTL, DRAFT-aware). */
    public function make(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing(['customer', 'project', 'items']);

        $pdf = Pdf::loadView('print.invoice', [
            'invoice' => $invoice,
            'company' => CompanyProfile::fromSettings($this->settings),
            'pdf' => true,
            'draft' => $invoice->isDraft(),
        ])->setPaper('a4');

        $options = $pdf->getDomPDF()->getOptions();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        return $pdf;
    }

    /** Raw PDF bytes (used for streaming a download response). */
    public function bytes(Invoice $invoice): string
    {
        return $this->make($invoice)->output();
    }

    /**
     * Write the PDF to a private, on-demand temp file and return its absolute
     * path. The caller is responsible for deleting it after use.
     */
    public function storeTemp(Invoice $invoice): string
    {
        $disk = Storage::disk('local');
        $relative = 'whatsapp-tmp/'.uniqid('inv_', true).'.pdf';
        $disk->put($relative, $this->bytes($invoice));

        return $disk->path($relative);
    }
}
