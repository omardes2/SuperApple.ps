<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\Settings;
use App\Support\CompanyProfile;
use Illuminate\View\View;

/**
 * Renders print-ready (A4, RTL) HTML for invoices and quotations. Internal
 * figures (service cost, margins, internal notes) are never included.
 */
class InvoicePrintController extends Controller
{
    public function invoice(Invoice $invoice, Settings $settings): View
    {
        $this->authorize('print', $invoice);

        $invoice->loadMissing(['customer', 'project', 'items']);

        return view('print.invoice', [
            'invoice' => $invoice,
            'company' => CompanyProfile::fromSettings($settings),
        ]);
    }

    public function quotation(Quotation $quotation, Settings $settings): View
    {
        $this->authorize('print', $quotation);

        $quotation->loadMissing(['customer', 'project', 'items']);

        return view('print.quotation', [
            'quotation' => $quotation,
            'company' => CompanyProfile::fromSettings($settings),
        ]);
    }
}
