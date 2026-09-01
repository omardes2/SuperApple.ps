<?php

namespace App\Support;

use App\Services\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * The company header data shared by the print view, the PDF renderer and any
 * document delivery. Kept in one place so the invoice document has a single
 * source for branding and there is no duplicated Settings reading.
 */
final class CompanyProfile
{
    /** Where the uploaded company logo lives, and the disk it lives on. */
    public const LOGO_DISK = 'local';

    /**
     * @return array<string,mixed>
     */
    public static function fromSettings(Settings $settings): array
    {
        $logoPath = (string) $settings->get('company', 'logo_path', '');

        return [
            'name' => $settings->get('company', 'name', config('app.name')),
            'phone' => $settings->get('company', 'phone', ''),
            'whatsapp' => $settings->get('company', 'whatsapp', ''),
            'email' => $settings->get('company', 'email', ''),
            'address' => $settings->get('company', 'address', ''),
            'tax_number' => $settings->get('company', 'tax_number', ''),
            'invoice_footer' => $settings->get('finance', 'invoice_footer', ''),
            'logo_path' => $logoPath,
            // Embedded as a data URI so the logo renders identically in the
            // browser print view and in the offline dompdf PDF (no public
            // symlink, no network fetch).
            'logo_data_uri' => self::logoDataUri($logoPath),
        ];
    }

    /**
     * Read a stored logo file and return it as a base64 data URI, or null when
     * there is no logo (or the file is missing).
     */
    public static function logoDataUri(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk(self::LOGO_DISK);
        if (! $disk->exists($path)) {
            return null;
        }

        $data = $disk->get($path);
        if ($data === null || $data === '') {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($data);
    }
}
