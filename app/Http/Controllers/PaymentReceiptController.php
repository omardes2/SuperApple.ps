<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Settings;
use Illuminate\View\View;

/**
 * Renders a print-ready (A4/A5, RTL) payment receipt. Internal figures (service
 * cost, margins, internal notes) are never shown — only what the customer needs:
 * amount received, its USD equivalent, and which invoices it settled.
 */
class PaymentReceiptController extends Controller
{
    public function receipt(Payment $payment, Settings $settings): View
    {
        $this->authorize('print', $payment);

        $payment->loadMissing(['customer', 'activeAllocations.invoice', 'receivedBy']);

        return view('print.receipt', [
            'payment' => $payment,
            'company' => [
                'name' => $settings->get('company', 'name', config('app.name')),
                'phone' => $settings->get('company', 'phone', ''),
                'address' => $settings->get('company', 'address', ''),
                'tax_number' => $settings->get('company', 'tax_number', ''),
                'invoice_footer' => $settings->get('finance', 'invoice_footer', ''),
            ],
        ]);
    }
}
