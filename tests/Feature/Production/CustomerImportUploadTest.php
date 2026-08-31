<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomersImport;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\CustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The real upload → preview path: a file chosen in the browser becomes a Livewire
 * TemporaryUploadedFile, is staged to a stable local path, and read by the parser.
 * This is the flow that was failing in production ("تعذّر قراءة الملف") because the
 * temporary upload's getRealPath() was handed straight to PhpSpreadsheet.
 */
class CustomerImportUploadTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const HEADERS = ['اسم العميل', 'رقم واتساب', 'نوع الرصيد', 'الرصيد الأصلي', 'سعر الصرف', 'الرصيد بالدولار', 'تاريخ الرصيد', 'ملاحظات'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /**
     * @param  list<array<int,mixed>>  $rows
     */
    private function build(array $rows, string $ext = 'xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach (self::HEADERS as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $val) {
                $sheet->setCellValue([$c + 1, $r + 2], $val);
            }
        }

        $writer = match ($ext) {
            'xls' => new XlsWriter($spreadsheet),
            'csv' => tap(new CsvWriter($spreadsheet), fn ($w) => $w->setUseBOM(true)), // Arabic-safe CSV
            default => new XlsxWriter($spreadsheet),
        };
        $path = tempnam(sys_get_temp_dir(), 'up').'.'.$ext;
        $writer->save($path);
        $content = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent('customers.'.$ext, $content);
    }

    private function debitRow(string $name, string $whatsapp = '970599000000'): array
    {
        return [$name, $whatsapp, 'مدين', 3100, 3.10, 1000, '31/08/2026', 'رصيد افتتاحي'];
    }

    private function upload(UploadedFile $file, RoleName $role = RoleName::SuperAdmin)
    {
        return Livewire::actingAs($this->makeUser($role))
            ->test(CustomersImport::class)
            ->set('file', $file)
            ->call('parse');
    }

    // ---- 1,3,4 formats succeed through the real Livewire upload ----

    public function test_real_xlsx_upload_reaches_preview(): void
    {
        $this->upload($this->build([$this->debitRow('شركة ألف')]))
            ->assertSet('step', 'preview')
            ->assertSet('parseError', null);
    }

    public function test_xls_upload_reaches_preview(): void
    {
        $this->upload($this->build([$this->debitRow('شركة باء')], 'xls'))
            ->assertSet('step', 'preview')
            ->assertSet('parseError', null);
    }

    public function test_csv_upload_reaches_preview(): void
    {
        $this->upload($this->build([$this->debitRow('شركة جيم')], 'csv'))
            ->assertSet('step', 'preview')
            ->assertSet('parseError', null);
    }

    // ---- 2 hashed/extension-less temp path still reads (service level) ----

    public function test_extensionless_local_path_reads_with_original_extension(): void
    {
        // Simulate a temp file whose on-disk name carries no useful extension.
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(self::HEADERS, null, 'A1');
        $spreadsheet->getActiveSheet()->fromArray($this->debitRow('بلا امتداد'), null, 'A2');
        $path = sys_get_temp_dir().'/'.uniqid('noext_'); // NO extension
        (new XlsxWriter($spreadsheet))->save($path);

        $service = app(CustomerImportService::class);
        // With the original extension supplied, the explicit reader is used.
        $withExt = $service->preview($path, 'xlsx');
        $this->assertTrue($withExt['ok']);
        // With no extension hint, content-sniff fallback still works.
        $noExt = $service->preview($path, null);
        $this->assertTrue($noExt['ok']);
        @unlink($path);
    }

    // ---- 5 fake file rejected safely ----

    public function test_fake_xlsx_is_rejected_without_importing(): void
    {
        $fake = UploadedFile::fake()->createWithContent('customers.xlsx', 'this is not a spreadsheet');
        $this->upload($fake)
            ->assertSet('step', 'upload')
            ->assertSet('parseError', fn ($v) => $v !== null);
        $this->assertSame(0, Customer::count());
    }

    public function test_wrong_extension_is_rejected_by_validation(): void
    {
        $bad = UploadedFile::fake()->createWithContent('notes.txt', 'hello');
        Livewire::actingAs($this->makeUser(RoleName::SuperAdmin))
            ->test(CustomersImport::class)
            ->set('file', $bad)
            ->call('parse')
            ->assertHasErrors('file');
    }

    // ---- 6-10 parsed content ----

    public function test_preview_returns_correct_rows_and_stats(): void
    {
        $c = $this->upload($this->build([
            $this->debitRow('شركة ألف'),
            ['شركة باء', '', 'دائن', 620, 3.10, 200, '31/08/2026', ''],
        ]))->assertSet('step', 'preview');

        $stats = $c->get('stats');
        $this->assertSame(2, $stats['total_rows']);
        $this->assertSame(1, $stats['debit_count']);
        $this->assertSame(1, $stats['credit_count']);
    }

    public function test_arabic_headers_and_names_are_parsed(): void
    {
        $rows = $this->upload($this->build([$this->debitRow('شركة الأمل التجارية')]))->get('rows');
        $this->assertSame('شركة الأمل التجارية', $rows[0]['name']);
        $this->assertSame('debit', $rows[0]['type']);
    }

    public function test_blank_whatsapp_is_accepted_in_preview(): void
    {
        $rows = $this->upload($this->build([$this->debitRow('بلا واتساب', whatsapp: '')]))->get('rows');
        $this->assertNotSame('error', $rows[0]['status']);
        $this->assertNull($rows[0]['whatsapp']);
    }

    public function test_same_whatsapp_different_names_are_both_new(): void
    {
        $rows = $this->upload($this->build([
            $this->debitRow('شركة أولى', whatsapp: '970599000000'),
            $this->debitRow('شركة ثانية', whatsapp: '970599000000'),
        ]))->get('rows');

        // Shared WhatsApp must NOT mark either row as a duplicate.
        $this->assertNull($rows[0]['existing_customer_id']);
        $this->assertNull($rows[1]['existing_customer_id']);
        $this->assertNotSame('duplicate', $rows[0]['status']);
        $this->assertNotSame('duplicate', $rows[1]['status']);
    }

    // ---- 11-14 preview persists nothing ----

    public function test_preview_persists_no_business_data(): void
    {
        $this->upload($this->build([
            $this->debitRow('شركة ألف'),
            ['شركة باء', '', 'دائن', 620, 3.10, 200, '31/08/2026', ''],
        ]))->assertSet('step', 'preview');

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, CustomerOpeningBalance::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    // ---- 15 conversion ----

    public function test_ils_over_rate_gives_usd_in_preview(): void
    {
        $rows = $this->upload($this->build([$this->debitRow('شركة ألف')]))->get('rows');
        $this->assertSame('1000.00', $rows[0]['usd']); // 3100 / 3.10
    }

    // ---- 16 temp file lifecycle ----

    public function test_staged_temp_file_is_cleaned_up_after_preview(): void
    {
        $dir = storage_path('app/private/imports/tmp');
        $before = File::exists($dir) ? count(File::files($dir)) : 0;

        $this->upload($this->build([$this->debitRow('شركة ألف')]))->assertSet('step', 'preview');

        $after = File::exists($dir) ? count(File::files($dir)) : 0;
        $this->assertSame($before, $after); // the staged copy was deleted
        $this->assertSame(0, count(Storage::disk('local')->files('imports/tmp')));
    }
}
