<?php

namespace App\Livewire\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download from a Livewire action. Every caller must gate the
 * export on the appropriate permission (e.g. reports.export) and pass only rows
 * the current user is authorised to see — export never widens visibility.
 */
trait ExportsCsv
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int,mixed>>  $rows
     */
    protected function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Arabic correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
