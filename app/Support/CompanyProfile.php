<?php

namespace App\Support;

use App\Services\Settings;

/**
 * The company header data shared by the print view, the PDF renderer and any
 * document delivery. Kept in one place so the invoice document has a single
 * source for branding and there is no duplicated Settings reading.
 */
final class CompanyProfile
{
    /**
     * @return array<string,mixed>
     */
    public static function fromSettings(Settings $settings): array
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
