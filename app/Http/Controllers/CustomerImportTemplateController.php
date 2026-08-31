<?php

namespace App\Http\Controllers;

use App\Services\CustomerImportService;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the customer-import Excel template as a normal HTTP download.
 *
 * This is intentionally a plain GET controller rather than a Livewire action:
 * serving a binary file through a Livewire action forces the whole file to be
 * captured in an output buffer and base64-embedded into the /livewire/update
 * XHR JSON, which is fragile and was returning 500 in production. A dedicated
 * route hands the browser the bytes directly.
 *
 * Gated by customers.import (route middleware + explicit authorize). Nothing is
 * persisted — the workbook is generated in memory and streamed, never written
 * to public storage.
 */
class CustomerImportTemplateController extends Controller
{
    public function download(): StreamedResponse
    {
        $this->authorize('customers.import');

        try {
            $spreadsheet = $this->buildTemplate();
            $writer = new XlsxWriter($spreadsheet);
        } catch (\Throwable $e) {
            // Never leak a stack trace; log the technical cause and fail cleanly.
            Log::error('Customer import template generation failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            abort(500, 'تعذّر توليد نموذج Excel حالياً. يرجى المحاولة لاحقاً.');
        }

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'customers-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /** Build the in-memory template workbook: official headers + one example row. */
    private function buildTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('العملاء');
        $sheet->setRightToLeft(true);

        // Headers must exactly match the aliases the parser accepts.
        foreach (CustomerImportService::templateHeaders() as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        // A single illustrative example row (no real production data).
        $sheet->fromArray(
            ['شركة مثال', '970599000000', 'مدين', 3100, 3.10, 1000, '31/08/2026', 'رصيد افتتاحي'],
            null,
            'A2'
        );

        return $spreadsheet;
    }
}
