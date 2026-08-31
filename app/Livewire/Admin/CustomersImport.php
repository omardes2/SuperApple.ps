<?php

namespace App\Livewire\Admin;

use App\Services\CustomerImportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
        // Validate by the browser-provided extension rather than a content-sniffed
        // MIME: an .xlsx is a ZIP container and is often reported as
        // application/zip on Linux, which a strict `mimes` rule would wrongly
        // reject. A file that passes here but is not a real spreadsheet is still
        // rejected safely when PhpSpreadsheet fails to read it.
        $this->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv'],
        ], [], ['file' => 'الملف']);
    }

    public function parse(CustomerImportService $service): void
    {
        $this->authorize('customers.import');
        $this->validateFile();

        $this->reset(['rows', 'stats', 'warnings', 'parseError', 'report', 'imported']);

        $this->originalFilename = $this->file->getClientOriginalName();
        // Trust the ORIGINAL extension, not the hashed temp filename, to pick the
        // reader; default to xlsx when the browser sent none.
        $extension = strtolower($this->file->getClientOriginalExtension() ?: 'xlsx');

        // Copy the upload to a stable, private, local path. Livewire's temporary
        // upload may live on a non-local disk (or expose a path PhpSpreadsheet
        // cannot open), so we never hand its getRealPath() straight to the reader.
        $localPath = null;
        try {
            $localPath = $this->stageUpload($extension);
            $this->fingerprint = hash_file('sha256', $localPath) ?: '';
            $result = $service->preview($localPath, $extension);
        } catch (\Throwable $e) {
            report($e);
            $result = ['ok' => false, 'error' => 'تعذّر تحضير الملف المرفوع للمعاينة. يرجى إعادة المحاولة.'];
        } finally {
            // The preview reads the file synchronously; the parsed rows now live in
            // component state, so the on-disk copy is no longer needed.
            if ($localPath !== null) {
                Storage::disk('local')->delete($this->stagedRelativePath($localPath));
            }
        }

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
     * Copy the Livewire temporary upload to a real local filesystem path and
     * return that absolute path. Works regardless of which disk backs the
     * temporary upload, because it streams through the framework rather than
     * relying on getRealPath().
     */
    private function stageUpload(string $extension): string
    {
        $relative = $this->file->storeAs(
            'imports/tmp',
            Str::uuid()->toString().'.'.$extension,
            ['disk' => 'local']
        );

        if ($relative === false) {
            throw new \RuntimeException('failed to stage the uploaded import file');
        }

        return Storage::disk('local')->path($relative);
    }

    /** The local-disk-relative path for a staged absolute path (for deletion). */
    private function stagedRelativePath(string $absolute): string
    {
        return 'imports/tmp/'.basename($absolute);
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
