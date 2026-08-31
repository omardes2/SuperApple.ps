<?php

namespace App\Livewire\Admin;

use App\Services\CustomerImportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import of customers + opening balances from an Aliphia-style Excel export.
 * Upload → parse → preview → confirm → posted (customers + official opening-balance
 * journals). Nothing is written until the user confirms; the whole confirmed set
 * is imported atomically. No invoices, no payments — opening balances only.
 */
#[Layout('layouts.app')]
#[Title('استيراد العملاء والأرصدة')]
class CustomersImport extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    /** upload | preview | done */
    public string $step = 'upload';

    public ?string $originalFilename = null;

    /** @var list<array<string,mixed>> */
    public array $rows = [];

    /** @var array<string,mixed> */
    public array $stats = [];

    /** @var list<string> */
    public array $warnings = [];

    public ?string $parseError = null;

    /** @var array<string,mixed> */
    public array $report = [];

    /** Fingerprint of the previewed file — blocks a second confirm of the same set. */
    public string $fingerprint = '';

    public bool $imported = false;

    public function mount(): void
    {
        $this->authorize('customers.import');
    }

    public function updatedFile(): void
    {
        // Validate as soon as a file is chosen so the user gets immediate feedback.
        $this->validateFile();
    }

    private function validateFile(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv'],
        ], [], ['file' => 'الملف']);
    }

    public function parse(CustomerImportService $service): void
    {
        $this->authorize('customers.import');
        $this->validateFile();

        $this->reset(['rows', 'stats', 'warnings', 'parseError', 'report', 'imported']);

        $path = $this->file->getRealPath();
        $this->originalFilename = $this->file->getClientOriginalName();
        $this->fingerprint = hash_file('sha256', $path) ?: '';

        $result = $service->preview($path);

        if (! $result['ok']) {
            $this->parseError = $result['error'];
            $this->step = 'upload';

            return;
        }

        $this->rows = $result['rows'];
        $this->stats = $result['stats'];
        $this->warnings = $result['warnings'];
        $this->step = 'preview';
    }

    /**
     * Toggle a duplicate (existing-customer) row between skip and attach. Only
     * non-error rows that matched an existing customer may be attached, and only
     * when that customer has no posted opening balance already.
     */
    public function setAction(int $index, string $action): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }
        $row = $this->rows[$index];
        if ($row['status'] === CustomerImportService::STATUS_ERROR || empty($row['existing_customer_id'])) {
            return;
        }
        if ($action === CustomerImportService::ACTION_ATTACH && $row['has_existing_ob'] && $row['has_balance']) {
            return; // cannot add a second opening balance
        }
        if (! in_array($action, [CustomerImportService::ACTION_SKIP, CustomerImportService::ACTION_ATTACH], true)) {
            return;
        }
        $this->rows[$index]['action'] = $action;
    }

    public function confirmImport(CustomerImportService $service): void
    {
        $this->authorize('customers.import');

        // Double-submit / stale-state protection.
        if ($this->imported || $this->step !== 'preview' || $this->rows === []) {
            return;
        }

        try {
            $report = $service->import($this->rows, [
                'filename' => $this->originalFilename,
                'row_count' => count($this->rows),
            ]);
        } catch (\Throwable $e) {
            $this->parseError = 'تعذّر إتمام الاستيراد ولم يتم حفظ أي بيانات: '.$e->getMessage();

            return;
        }

        $this->imported = true;
        $this->report = $report;
        $this->step = 'done';
        $this->cleanupFile();
    }

    public function cancel(): void
    {
        $this->cleanupFile();
        $this->reset(['file', 'rows', 'stats', 'warnings', 'parseError', 'report', 'imported', 'fingerprint', 'originalFilename']);
        $this->step = 'upload';
        $this->resetErrorBag();
    }

    private function cleanupFile(): void
    {
        try {
            $this->file?->delete();
        } catch (\Throwable) {
            // temp file already gone — nothing to clean up
        }
        $this->file = null;
    }

    /** Stream a ready-to-fill xlsx template with the accepted Arabic headers. */
    public function downloadTemplate(): StreamedResponse
    {
        $this->authorize('customers.import');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $headers = CustomerImportService::templateHeaders();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        // One illustrative example row.
        $sheet->fromArray(['شركة ألف', '', 'مدين', 3100, 3.10, 1000, '31/08/2026', 'مثال'], null, 'A2');

        $writer = new XlsxWriter($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'customers-import-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array<string,mixed> */
    public function render()
    {
        $importable = 0;
        foreach ($this->rows as $row) {
            if ($row['status'] !== CustomerImportService::STATUS_ERROR
                && in_array($row['action'], [CustomerImportService::ACTION_IMPORT, CustomerImportService::ACTION_ATTACH], true)) {
                $importable++;
            }
        }

        return view('livewire.admin.customers-import', [
            'importableCount' => $importable,
            'canImport' => Auth::user()->can('customers.import'),
        ]);
    }
}
