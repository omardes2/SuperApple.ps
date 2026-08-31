<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\CustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The customer-import Excel template download. It is a plain GET route (not a
 * Livewire binary action, which was returning 500 in production), gated by
 * customers.import, and must produce a real, re-readable xlsx with the exact
 * headers the parser accepts — without persisting anything.
 */
class CustomerImportTemplateTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const URL = '/admin/customers/import/template';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Persist the streamed body to a temp path and hand back the path. */
    private function streamToFile(TestResponse $response): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return $path;
    }

    public function test_authorized_user_can_download_template_with_200(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $this->get(self::URL)->assertOk();
    }

    public function test_super_admin_can_download_template(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $this->get(self::URL)->assertOk();
    }

    public function test_content_type_is_xlsx(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $response = $this->get(self::URL);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    public function test_content_disposition_is_attachment_with_xlsx_filename(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $disposition = $this->get(self::URL)->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('customers-import-template.xlsx', $disposition);
        $this->assertStringEndsWith('.xlsx', 'customers-import-template.xlsx');
    }

    public function test_output_is_a_readable_xlsx_with_a_sheet(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $path = $this->streamToFile($this->get(self::URL)->assertOk());

        $spreadsheet = IOFactory::load($path);
        $this->assertGreaterThanOrEqual(1, $spreadsheet->getSheetCount());
        @unlink($path);
    }

    public function test_template_has_the_exact_parser_headers_in_order(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $path = $this->streamToFile($this->get(self::URL)->assertOk());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $expected = CustomerImportService::templateHeaders();
        foreach ($expected as $i => $header) {
            $this->assertSame($header, $sheet->getCell([$i + 1, 1])->getValue());
        }
        @unlink($path);
    }

    public function test_arabic_headers_are_intact(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $path = $this->streamToFile($this->get(self::URL)->assertOk());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('اسم العميل', $sheet->getCell('A1')->getValue());
        $this->assertSame('نوع الرصيد', $sheet->getCell('C1')->getValue());
        $this->assertSame('تاريخ الرصيد', $sheet->getCell('G1')->getValue());
        @unlink($path);
    }

    public function test_example_row_is_present_and_valid(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $path = $this->streamToFile($this->get(self::URL)->assertOk());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('شركة مثال', $sheet->getCell('A2')->getValue());
        $this->assertSame('مدين', $sheet->getCell('C2')->getValue());
        $this->assertEquals(3100, $sheet->getCell('D2')->getValue());
        $this->assertEquals(3.10, $sheet->getCell('E2')->getValue());
        @unlink($path);
    }

    public function test_the_generated_template_round_trips_through_the_parser(): void
    {
        // The template a user downloads must itself be importable — headers align.
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $path = $this->streamToFile($this->get(self::URL)->assertOk());

        $result = app(CustomerImportService::class)->preview($path);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['rows']); // the single example row
        $this->assertSame('ready', $result['rows'][0]['status']);
        $this->assertSame('1000.00', $result['rows'][0]['usd']); // 3100 / 3.10
        @unlink($path);
    }

    // ---- Authorization ----

    public function test_unauthorized_backoffice_user_gets_403(): void
    {
        // HR manager reaches the admin area but lacks customers.import → 403.
        $user = $this->makeUser(RoleName::HrManager);
        $this->assertFalse($user->can('customers.import'));
        $this->actingAs($user)->get(self::URL)->assertForbidden();
    }

    public function test_employee_cannot_reach_template(): void
    {
        [$user] = $this->makeStaff(RoleName::Employee);
        $this->actingAs($user)->get(self::URL)->assertRedirect(route('employee.dashboard'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(self::URL)->assertRedirect(route('login'));
    }

    // ---- Persists nothing ----

    public function test_download_creates_no_business_data(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $this->get(self::URL)->assertOk()->streamedContent();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, CustomerOpeningBalance::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_download_leaves_no_temp_file_in_storage(): void
    {
        $dir = storage_path('app');
        $before = File::exists($dir) ? File::allFiles($dir) : [];

        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $this->get(self::URL)->assertOk()->streamedContent();

        $after = File::exists($dir) ? File::allFiles($dir) : [];
        // The template streams straight to the client — nothing lands on disk.
        $this->assertCount(count($before), $after);
    }
}
