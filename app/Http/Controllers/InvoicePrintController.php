<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\Settings;
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
            'company' => $this->company($settings),
        ]);
    }

    public function quotation(Quotation $quotation, Settings $settings): View
    {
        $this->authorize('print', $quotation);

        $quotation->loadMissing(['customer', 'project', 'items']);

        return view('print.quotation', [
            'quotation' => $quotation,
            'company' => $this->company($settings),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function company(Settings $settings): array
    {
        return [
            'name' => $settings->get('company', 'name', config('app.name')),
            'phone' => $settings->get('company', 'phone', ''),
            'whatsapp' => $settings->get('company', 'whatsapp', ''),
            'address' => $settings->get('company', 'address', ''),
            'tax_number' => $settings->get('company', 'tax_number', ''),
            'invoice_footer' => $settings->get('finance', 'invoice_footer', ''),
        ];
    }
}
