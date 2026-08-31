<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomersImport;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Services\CustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The real Aliphia CSV export has quirks the first parser missed: headers with a
 * trailing currency token ("الرصيد الأصلي ILS", "سعر الصرف USD/ILS", "الرصيد
 * الافتتاحي USD"), amounts quoted with thousands separators and a $ sign
 * ("32,370.25", "$10,442.02"), a UTF-8 BOM and CRLF line endings. These must all
 * parse into real debit/credit opening balances.
 */
class CustomerImportAliphiaFormatTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** Headers exactly as they appear in the Aliphia export. */
    private const HEADERS = 'اسم العميل,رقم واتساب,نوع الرصيد,الرصيد الأصلي ILS,سعر الصرف USD/ILS,الرصيد الافتتاحي USD,تاريخ الرصيد,ملاحظات';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Build a BOM + CRLF UTF-8 CSV like the real export and wrap as an upload. */
    private function aliphiaCsv(array $dataLines): UploadedFile
    {
        $content = "\u{FEFF}".self::HEADERS."\r\n".implode("\r\n", $dataLines)."\r\n";

        return UploadedFile::fake()->createWithContent('عملاء.csv', $content);
    }

    private function preview(UploadedFile $file)
    {
        return Livewire::actingAs($this->makeUser(RoleName::SuperAdmin))
            ->test(CustomersImport::class)
            ->set('file', $file)
            ->call('parse');
    }

    public function test_currency_suffixed_headers_map_to_balance_columns(): void
    {
        $c = $this->preview($this->aliphiaCsv([
            'ازياء الملكة,972522719306,مدين,"32,370.25",3.1000,"$10,442.02",31/08/2026,ملاحظة',
        ]))->assertSet('step', 'preview');

        // The balance columns were detected (not treated as "customers only").
        $this->assertSame([], $c->get('warnings'));
        $row = $c->get('rows')[0];
        $this->assertSame('debit', $row['type']);
        $this->assertSame('32370.25', $row['ils']);
        $this->assertSame('3.100000', $row['rate']);
        $this->assertSame('10442.02', $row['usd']); // 32,370.25 / 3.1
        $this->assertSame('ready', $row['status']);
    }

    public function test_thousands_separators_and_dollar_sign_are_stripped(): void
    {
        $row = $this->preview($this->aliphiaCsv([
            'شركة كبيرة,,مدين,"1,234,567.89",3.5,"$352,733.68",31/08/2026,',
        ]))->get('rows')[0];

        $this->assertSame('1234567.89', $row['ils']);
        $this->assertNotSame('error', $row['status']);
    }

    public function test_credit_rows_parse_from_the_real_format(): void
    {
        $row = $this->preview($this->aliphiaCsv([
            'عميل دائن,,دائن,"3,100.00",3.10,"$1,000.00",31/08/2026,',
        ]))->get('rows')[0];

        $this->assertSame('credit', $row['type']);
        $this->assertSame('1000.00', $row['usd']);
        $this->assertSame('ready', $row['status']);
    }

    public function test_blank_whatsapp_column_in_real_format_is_accepted(): void
    {
        $row = $this->preview($this->aliphiaCsv([
            'عميل بلا رقم,,مدين,"3,100.00",3.10,"$1,000.00",31/08/2026,',
        ]))->get('rows')[0];

        $this->assertNull($row['whatsapp']);
        $this->assertNotSame('error', $row['status']);
    }

    public function test_full_import_of_real_format_posts_opening_balances(): void
    {
        $this->preview($this->aliphiaCsv([
            'ازياء الملكة,972522719306,مدين,"32,370.25",3.1000,"$10,442.02",31/08/2026,رصيد',
            'المهندس سنتر,972599302228,مدين,"15,676.91",3.1000,"$5,057.07",31/08/2026,رصيد',
            'عميل دائن,,دائن,"3,100.00",3.1000,"$1,000.00",31/08/2026,رصيد',
        ]))->assertSet('step', 'preview')->call('confirmImport')->assertSet('step', 'done');

        $this->assertSame(3, Customer::count());
        $this->assertSame(3, CustomerOpeningBalance::count());

        $ob = Customer::where('name', 'ازياء الملكة')->firstOrFail()->postedOpeningBalance();
        $this->assertSame('debit', $ob->type);
        // USD is the official amount recomputed from |ILS| / rate.
        $this->assertSame('10442.02', $ob->amount_usd);
        // The service snapshots ILS as usd × rate (10442.02 × 3.10), which may
        // differ from the file's raw ILS by a rounding agora — expected.
        $this->assertSame('32370.26', $ob->amount_ils);

        $credit = Customer::where('name', 'عميل دائن')->firstOrFail()->postedOpeningBalance();
        $this->assertSame('credit', $credit->type);
        $this->assertSame('1000.00', $credit->amount_usd);
    }

    public function test_service_reads_the_real_headers_directly(): void
    {
        // Header-resolution unit check against every real Aliphia column label.
        $csv = "\u{FEFF}".self::HEADERS."\r\n".'عميل,970599000000,مدين,"3,100.00",3.10,"$1,000.00",31/08/2026,x'."\r\n";
        $path = tempnam(sys_get_temp_dir(), 'al').'.csv';
        file_put_contents($path, $csv);

        $result = app(CustomerImportService::class)->preview($path, 'csv');
        @unlink($path);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['warnings']); // balance columns recognised
        $this->assertSame('1000.00', $result['rows'][0]['usd']);
    }
}
