<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\SystemAccountKey;
use App\Livewire\Admin\CustomersImport;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Services\AccountingService;
use App\Services\CustomerImportService;
use App\Services\CustomerOpeningBalanceService;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Bulk import of customers + opening balances from an Aliphia-style Excel file.
 * Everything routes through the official CustomerOpeningBalanceService, so the
 * accounting (Dr AR / Cr Opening-Balance-Equity) is identical to the manual flow.
 * ILS is the source amount; USD is recomputed = |ILS| / rate. No invoices, no
 * payments, no fake WhatsApp numbers, no parallel accounting.
 */
class CustomerImportTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** Default Arabic headers matching the real file. */
    private const HEADERS = ['اسم العميل', 'رقم واتساب', 'نوع الرصيد', 'الرصيد الأصلي', 'سعر الصرف', 'الرصيد بالدولار', 'تاريخ الرصيد', 'ملاحظات'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /**
     * Build a spreadsheet from rows and return it as an UploadedFile.
     *
     * @param  list<array<int,mixed>>  $rows
     */
    private function sheet(array $rows, string $ext = 'xlsx', ?array $headers = null): UploadedFile
    {
        $headers ??= self::HEADERS;
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $val) {
                $sheet->setCellValue([$c + 1, $r + 2], $val);
            }
        }

        $writer = $ext === 'xls' ? new XlsWriter($spreadsheet) : new XlsxWriter($spreadsheet);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.'.$ext;
        $writer->save($path);
        $content = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent('customers.'.$ext, $content);
    }

    /** A single debit row: name, whatsapp, نوع, ILS, rate, USD, date, notes. */
    private function debitRow(string $name, string $ils = '3100', string $rate = '3.10', string $usd = '1000', string $whatsapp = '', string $date = '31/08/2026'): array
    {
        return [$name, $whatsapp, 'مدين', $ils, $rate, $usd, $date, ''];
    }

    private function importComponent(?UploadedFile $file = null, RoleName $role = RoleName::SuperAdmin)
    {
        $user = $this->makeUser($role);

        $c = Livewire::actingAs($user)->test(CustomersImport::class);
        if ($file) {
            $c->set('file', $file)->call('parse');
        }

        return $c;
    }

    // ---- 1-3 Authorization ----

    public function test_authorized_user_sees_import_button_on_customers_page(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->assertTrue($gm->can('customers.import'));
        $this->actingAs($gm)->get(route('admin.customers'))->assertOk()->assertSee('استيراد العملاء والأرصدة');
    }

    public function test_unauthorized_user_does_not_see_button_and_cannot_reach_page(): void
    {
        [$user] = $this->makeStaff(RoleName::Employee);
        $this->assertFalse($user->can('customers.import'));
        // Employee is redirected out of the admin area entirely.
        $this->actingAs($user)->get(route('admin.customers.import'))->assertRedirect(route('employee.dashboard'));
    }

    public function test_admin_role_without_import_permission_is_forbidden(): void
    {
        // A back-office role lacking customers.import cannot reach the page.
        $user = $this->makeUser(RoleName::HrManager);
        $this->assertFalse($user->can('customers.import'));
        $this->actingAs($user)->get(route('admin.customers.import'))->assertForbidden();
    }

    public function test_backend_import_is_authorized_even_if_route_bypassed(): void
    {
        $user = $this->makeUser(RoleName::HrManager); // no customers.import
        Livewire::actingAs($user)->test(CustomersImport::class)->assertForbidden();
    }

    // ---- 4-6 Upload / file types ----

    public function test_xlsx_is_accepted(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file)->assertSet('step', 'preview')->assertSet('parseError', null);
    }

    public function test_xls_is_accepted(): void
    {
        $file = $this->sheet([$this->debitRow('شركة باء')], 'xls');
        $this->importComponent($file)->assertSet('step', 'preview');
    }

    public function test_invalid_file_is_rejected(): void
    {
        $bad = UploadedFile::fake()->createWithContent('notes.txt', 'just text');
        $this->importComponent()->set('file', $bad)->call('parse')->assertHasErrors('file');
    }

    // ---- 7-8 Preview persists nothing ----

    public function test_preview_does_not_persist_customers(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف'), $this->debitRow('شركة باء')]);
        $this->importComponent($file)->assertSet('step', 'preview');
        $this->assertSame(0, Customer::count());
    }

    public function test_preview_creates_no_journal_entries(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, CustomerOpeningBalance::count());
    }

    // ---- 9-11 Validation & WhatsApp ----

    public function test_customer_name_is_required(): void
    {
        $file = $this->sheet([['', '', 'مدين', '3100', '3.10', '1000', '31/08/2026', '']]);
        $c = $this->importComponent($file);
        $rows = $c->get('rows');
        $this->assertSame('error', $rows[0]['status']);
    }

    public function test_blank_whatsapp_is_accepted_and_stored_null(): void
    {
        $file = $this->sheet([$this->debitRow('بلا واتساب', whatsapp: '')]);
        $this->importComponent($file)->call('confirmImport')->assertSet('step', 'done');
        $customer = Customer::where('name', 'بلا واتساب')->firstOrFail();
        $this->assertNull($customer->whatsapp_number); // no fake number
    }

    public function test_no_fake_whatsapp_generated(): void
    {
        $file = $this->sheet([$this->debitRow('عميل بلا رقم', whatsapp: '')]);
        $this->importComponent($file)->call('confirmImport');
        foreach (['000000', 'N/A', 'na', '0'] as $fake) {
            $this->assertSame(0, Customer::where('whatsapp_number', $fake)->count());
        }
    }

    // ---- 12-14 Conversion & types ----

    public function test_ils_3100_over_rate_310_equals_usd_1000(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف', ils: '3100', rate: '3.10', usd: '1000')]);
        $this->importComponent($file)->call('confirmImport');
        $ob = Customer::where('name', 'شركة ألف')->firstOrFail()->postedOpeningBalance();
        $this->assertSame('1000.00', $ob->amount_usd);
        $this->assertSame('3100.00', $ob->amount_ils);
    }

    public function test_debit_imports_correctly(): void
    {
        $file = $this->sheet([$this->debitRow('عميل مدين')]);
        $this->importComponent($file)->call('confirmImport');
        $ob = Customer::where('name', 'عميل مدين')->firstOrFail()->postedOpeningBalance();
        $this->assertSame(CustomerOpeningBalance::TYPE_DEBIT, $ob->type);
        $this->assertSame('1000.00', $ob->remaining_usd); // debit is a receivable
    }

    public function test_credit_imports_correctly(): void
    {
        // 620 ILS / 3.10 = 200 USD credit.
        $file = $this->sheet([['شركة باء', '', 'دائن', '620', '3.10', '200', '31/08/2026', '']]);
        $this->importComponent($file)->call('confirmImport');
        $ob = Customer::where('name', 'شركة باء')->firstOrFail()->postedOpeningBalance();
        $this->assertSame(CustomerOpeningBalance::TYPE_CREDIT, $ob->type);
        $this->assertSame('200.00', $ob->amount_usd);
        $this->assertSame('0.00', $ob->remaining_usd); // credit is not a receivable
    }

    // ---- 15-17 Zero balance ----

    public function test_zero_balance_creates_customer_only(): void
    {
        $file = $this->sheet([['شركة جيم', '', '', '0', '', '', '', '']]);
        $this->importComponent($file)->call('confirmImport')->assertSet('step', 'done');
        $this->assertNotNull(Customer::where('name', 'شركة جيم')->first());
    }

    public function test_zero_balance_creates_no_opening_balance(): void
    {
        $file = $this->sheet([['شركة جيم', '', '', '0', '', '', '', '']]);
        $this->importComponent($file)->call('confirmImport');
        $this->assertSame(0, CustomerOpeningBalance::count());
    }

    public function test_zero_balance_creates_no_journal(): void
    {
        $file = $this->sheet([['شركة جيم', '', '', '0', '', '', '', '']]);
        $this->importComponent($file)->call('confirmImport');
        $this->assertSame(0, JournalEntry::count());
    }

    // ---- 18-21 Duplicates (name-first) ----

    public function test_existing_normalized_name_detected_as_duplicate(): void
    {
        $this->makeCustomer(['name' => 'شركة سوبر أبل']);
        // Extra internal spaces + surrounding whitespace must still match.
        $file = $this->sheet([$this->debitRow('  شركة   سوبر أبل  ')]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertSame('duplicate', $rows[0]['status']);
        $this->assertNotNull($rows[0]['existing_customer_id']);
    }

    public function test_whatsapp_alone_never_marks_duplicate(): void
    {
        // Same WhatsApp, different name → NOT a duplicate; a new customer is allowed.
        $this->makeCustomer(['name' => 'عميل قديم', 'whatsapp_number' => '970599111222']);
        $file = $this->sheet([$this->debitRow('عميل جديد مختلف', whatsapp: '0599111222')]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertNotSame('duplicate', $rows[0]['status']);
        $this->assertNull($rows[0]['existing_customer_id']);
    }

    public function test_name_and_whatsapp_both_match_is_strong_duplicate(): void
    {
        $this->makeCustomer(['name' => 'شركة الأفق', 'whatsapp_number' => '970599111222']);
        $file = $this->sheet([$this->debitRow('شركة الأفق', whatsapp: '0599111222')]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertSame('duplicate', $rows[0]['status']);
        $this->assertStringContainsString('الاسم والواتساب', implode(' ', $rows[0]['messages']));
    }

    public function test_duplicate_is_not_automatically_inserted(): void
    {
        $existing = $this->makeCustomer(['name' => 'شركة مكررة']);
        $file = $this->sheet([$this->debitRow('شركة مكررة')]);
        // Default action for an existing customer is skip.
        $this->importComponent($file)->call('confirmImport');
        $this->assertSame(1, Customer::where('name', 'شركة مكررة')->count());
        $this->assertSame(0, $existing->openingBalances()->count());
    }

    public function test_existing_opening_balance_is_protected(): void
    {
        $customer = $this->makeCustomer(['name' => 'له رصيد']);
        app(CustomerOpeningBalanceService::class)->create($customer, [
            'type' => 'debit', 'amount_usd' => '500', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $file = $this->sheet([$this->debitRow('له رصيد')]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertTrue($rows[0]['has_existing_ob']);
        $this->assertStringContainsString('رصيد افتتاحي مسبقاً', implode(' ', $rows[0]['messages']));
    }

    // ---- 22-25 Multi-row + accounting ----

    public function test_multiple_rows_import_correctly(): void
    {
        $file = $this->sheet([
            $this->debitRow('شركة ألف', ils: '3100', rate: '3.10', usd: '1000'),
            ['شركة باء', '', 'دائن', '620', '3.10', '200', '31/08/2026', ''],
            ['شركة جيم', '', '', '0', '', '', '', ''],
        ]);
        $this->importComponent($file)->call('confirmImport')->assertSet('step', 'done');

        $this->assertSame(3, Customer::count());
        $this->assertSame(2, CustomerOpeningBalance::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_opening_balance_uses_domain_service_and_posts_journal(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file)->call('confirmImport');
        $ob = Customer::where('name', 'شركة ألف')->firstOrFail()->postedOpeningBalance();
        $this->assertNotNull($ob->journal_entry_id);
        $this->assertSame(CustomerOpeningBalance::STATUS_POSTED, $ob->status);
    }

    public function test_debit_accounting_journal_is_correct(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف', ils: '3100', rate: '3.10', usd: '1000')]);
        $this->importComponent($file)->call('confirmImport');

        $ob = Customer::where('name', 'شركة ألف')->firstOrFail()->postedOpeningBalance();
        $entry = JournalEntry::with('lines')->whereKey($ob->journal_entry_id)->firstOrFail();
        $arId = app(AccountingService::class)->systemAccountId(SystemAccountKey::AccountsReceivable);
        $obeId = app(AccountingService::class)->systemAccountId(SystemAccountKey::OpeningBalanceEquity);

        $this->assertSame('3100.00', $entry->lines->firstWhere('account_id', $arId)->debit_ils);
        $this->assertSame('3100.00', $entry->lines->firstWhere('account_id', $obeId)->credit_ils);
        $this->assertSame((float) $entry->lines->sum('debit_ils'), (float) $entry->lines->sum('credit_ils'));
    }

    public function test_credit_accounting_journal_is_mirrored(): void
    {
        $file = $this->sheet([['شركة باء', '', 'دائن', '620', '3.10', '200', '31/08/2026', '']]);
        $this->importComponent($file)->call('confirmImport');

        $ob = Customer::where('name', 'شركة باء')->firstOrFail()->postedOpeningBalance();
        $entry = JournalEntry::with('lines')->whereKey($ob->journal_entry_id)->firstOrFail();
        $arId = app(AccountingService::class)->systemAccountId(SystemAccountKey::AccountsReceivable);
        $this->assertSame('620.00', $entry->lines->firstWhere('account_id', $arId)->credit_ils);
    }

    // ---- 26-28 Not invoices / payments / revenue ----

    public function test_no_invoice_created(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file)->call('confirmImport');
        $this->assertSame(0, Invoice::count());
    }

    public function test_no_payment_created(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file)->call('confirmImport');
        $this->assertSame(0, Payment::count());
    }

    public function test_opening_balance_is_not_revenue(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file)->call('confirmImport');
        $revenueId = app(AccountingService::class)->systemAccountId(SystemAccountKey::ServiceRevenue);
        $this->assertSame(0, JournalEntryLine::where('account_id', $revenueId)->count());
    }

    // ---- 29-31 Rate / conversion / rounding ----

    public function test_exchange_rate_must_be_positive(): void
    {
        $file = $this->sheet([['شركة ألف', '', 'مدين', '3100', '0', '1000', '31/08/2026', '']]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertSame('error', $rows[0]['status']);
    }

    public function test_incorrect_usd_vs_ils_conversion_is_flagged(): void
    {
        // 3100 / 3.10 = 1000, but the file claims 9999 → mismatch beyond tolerance.
        $file = $this->sheet([['شركة ألف', '', 'مدين', '3100', '3.10', '9999', '31/08/2026', '']]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertSame('error', $rows[0]['status']);
        $this->assertStringContainsString('تعارض', implode(' ', $rows[0]['messages']));
    }

    public function test_small_rounding_difference_is_tolerated(): void
    {
        // 1000 ILS / 3 = 333.333… → 333.33. A file USD of 333.33 is within tolerance.
        $file = $this->sheet([['عميل تقريب', '', 'مدين', '1000', '3', '333.33', '31/08/2026', '']]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertNotSame('error', $rows[0]['status']);
        $this->assertSame('333.33', $rows[0]['usd']);
    }

    // ---- 32 Dates ----

    public function test_text_date_31_08_2026_is_parsed_as_dd_mm_yyyy(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف', date: '31/08/2026')]);
        $rows = $this->importComponent($file)->get('rows');
        $this->assertSame('2026-08-31', $rows[0]['balance_date']);
    }

    public function test_native_excel_date_cell_is_parsed(): void
    {
        // Build a sheet with a real Excel date serial in the date column.
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach (self::HEADERS as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->fromArray(['شركة ألف', '', 'مدين', 3100, 3.10, 1000], null, 'A2');
        $serial = Date::PHPToExcel(new \DateTime('2026-08-31'));
        $sheet->setCellValue('G2', $serial);
        $sheet->getStyle('G2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        $writer = new XlsxWriter($spreadsheet);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer->save($path);
        $file = UploadedFile::fake()->createWithContent('customers.xlsx', file_get_contents($path));
        @unlink($path);

        $rows = $this->importComponent($file)->get('rows');
        $this->assertSame('2026-08-31', $rows[0]['balance_date']);
    }

    // ---- 33-34 Numbering / totals ----

    public function test_customer_numbering_uses_official_generator(): void
    {
        // A pre-existing customer establishes the running sequence.
        $existing = app(CustomerService::class)->create(['name' => 'أساس', 'whatsapp_number' => null]);
        $file = $this->sheet([$this->debitRow('عميل جديد')]);
        $this->importComponent($file)->call('confirmImport');

        $new = Customer::where('name', 'عميل جديد')->firstOrFail();
        $this->assertNotEmpty($new->customer_number);
        $this->assertNotSame($existing->customer_number, $new->customer_number);
    }

    public function test_final_report_totals_are_correct(): void
    {
        $file = $this->sheet([
            $this->debitRow('شركة ألف', ils: '3100', rate: '3.10', usd: '1000'),
            ['شركة باء', '', 'دائن', '620', '3.10', '200', '31/08/2026', ''],
            ['شركة جيم', '', '', '0', '', '', '', ''],
        ]);
        $c = $this->importComponent($file)->call('confirmImport');
        $report = $c->get('report');

        $this->assertSame(3, $report['created_customers']);
        $this->assertSame(2, $report['opening_balances']);
        $this->assertSame(1, $report['zero_balance']);
        $this->assertSame('3100.00', $report['debit_ils']);
        $this->assertSame('620.00', $report['credit_ils']);
        $this->assertSame('2480.00', $report['net_ils']); // 3100 − 620
    }

    // ---- 35-38 Safety: double submit, rollback, audit, cleanup ----

    public function test_double_submission_does_not_duplicate_data(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $c = $this->importComponent($file)->call('confirmImport')->assertSet('step', 'done');
        $c->call('confirmImport'); // second click — guarded
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, CustomerOpeningBalance::count());
    }

    public function test_import_rolls_back_entirely_on_failure(): void
    {
        $this->makeUser(RoleName::SuperAdmin);
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $service = app(CustomerImportService::class);

        // A well-formed new row plus a poisoned attach row pointing at a missing id.
        $rows = [
            [
                'line' => 2, 'name' => 'صالح', 'whatsapp' => null, 'type' => 'debit', 'ils' => '3100.00',
                'rate' => '3.100000', 'usd' => '1000.00', 'balance_date' => '2026-08-31', 'notes' => null,
                'has_balance' => true, 'status' => 'ready', 'messages' => [], 'existing_customer_id' => null,
                'existing_customer_name' => null, 'has_existing_ob' => false, 'action' => 'import',
            ],
            [
                'line' => 3, 'name' => 'مفقود', 'whatsapp' => null, 'type' => 'debit', 'ils' => '3100.00',
                'rate' => '3.100000', 'usd' => '1000.00', 'balance_date' => '2026-08-31', 'notes' => null,
                'has_balance' => true, 'status' => 'duplicate', 'messages' => [], 'existing_customer_id' => 999999,
                'existing_customer_name' => 'مفقود', 'has_existing_ob' => false, 'action' => 'attach',
            ],
        ];

        try {
            $service->import($rows, ['filename' => 'x.xlsx', 'row_count' => 2]);
            $this->fail('expected the import to throw');
        } catch (\Throwable) {
            // expected
        }

        // The first (valid) row must NOT have been committed.
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, CustomerOpeningBalance::count());
    }

    public function test_audit_entry_is_created(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file)->call('confirmImport');
        $log = AuditLog::where('action', 'customers_imported')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, (int) ($log->new_values['created_customers'] ?? 0));
    }

    public function test_temporary_file_is_cleaned_up_after_import(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $c = $this->importComponent($file)->call('confirmImport')->assertSet('step', 'done');
        $this->assertNull($c->get('file'));
    }

    public function test_cancel_resets_state(): void
    {
        $file = $this->sheet([$this->debitRow('شركة ألف')]);
        $this->importComponent($file)->assertSet('step', 'preview')
            ->call('cancel')
            ->assertSet('step', 'upload')
            ->assertSet('rows', []);
    }

    // ---- 39-40 Existing flows stay green ----

    public function test_manual_opening_balance_flow_still_works(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $customer = $this->makeCustomer(['name' => 'عميل يدوي']);
        $ob = app(CustomerOpeningBalanceService::class)->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);
        $this->assertSame('3100.00', $ob->amount_ils);
    }

    public function test_missing_name_header_stops_preview_with_message(): void
    {
        $file = $this->sheet([['x', 'y']], headers: ['رقم واتساب', 'ملاحظات']);
        $this->importComponent($file)->assertSet('step', 'upload')->assertSet('parseError', fn ($v) => $v !== null);
    }

    public function test_missing_type_and_rate_columns_stops_when_balance_present(): void
    {
        // Has a balance column but no type/rate → cannot post; must stop.
        $file = $this->sheet([['شركة ألف', '3100']], headers: ['اسم العميل', 'الرصيد الأصلي']);
        $c = $this->importComponent($file);
        $c->assertSet('step', 'upload');
        $this->assertNotNull($c->get('parseError'));
    }
}
