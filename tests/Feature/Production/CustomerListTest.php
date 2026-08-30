<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomersIndex;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The customer list page: a compact table (number, name, WhatsApp, status,
 * tasks, outstanding balance, actions), a three-control filter bar (search,
 * status, balance) and four summary cards. Outstanding balances are computed
 * per-document (each invoice at its own locked rate — no global rate), batched
 * to avoid N+1, and gated behind the finance permission.
 */
class CustomerListTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function list(RoleName $role = RoleName::GeneralManager): Testable
    {
        return Livewire::actingAs($this->makeUser($role))->test(CustomersIndex::class);
    }

    /** Post a payment that allocates $amount of an invoice, reducing its remaining. */
    private function payInvoice(Customer $customer, Invoice $invoice, string $amountUsd, string $rate = '3.00'): void
    {
        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->cashAccount('USD')->id,
            'customer_id' => $customer->id,
            'payment_currency' => 'USD',
            'payment_amount' => $amountUsd,
            'exchange_rate' => $rate,
            'payment_date' => '2026-08-10',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => $amountUsd]]);
    }

    // ---- Columns present ----

    public function test_table_contains_customer_code(): void
    {
        $this->makeCustomer(['customer_number' => 'CUS-00042', 'whatsapp_number' => '0599432037']);
        $this->list()->assertSee('CUS-00042');
    }

    public function test_table_contains_customer_name(): void
    {
        $this->makeCustomer(['name' => 'توفير اون لاين', 'whatsapp_number' => '0599432037']);
        $this->list()->assertSee('توفير اون لاين');
    }

    public function test_table_contains_whatsapp(): void
    {
        $this->makeCustomer(['name' => 'عميل', 'whatsapp_number' => '0599432037']);
        $this->list()->assertSee('0599432037');
    }

    public function test_table_contains_status_column(): void
    {
        $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->list()->assertSee('الحالة')->assertSee('نشط');
    }

    public function test_table_contains_tasks_column(): void
    {
        $this->list()->assertSee('المهام');
    }

    public function test_table_contains_outstanding_balance_column(): void
    {
        $this->list()->assertSee('الرصيد المتبقي');
    }

    // ---- Columns removed ----

    public function test_table_has_no_contact_person_column(): void
    {
        $this->list()->assertDontSee('المسؤول');
    }

    public function test_table_has_no_legacy_phone_column(): void
    {
        $this->list()->assertDontSee('الهاتف');
    }

    public function test_table_has_no_city_column(): void
    {
        $this->list()->assertDontSee('المدينة');
    }

    public function test_table_has_no_category_column(): void
    {
        $this->list()->assertDontSee('التصنيف');
    }

    public function test_table_has_no_source_column(): void
    {
        $this->list()->assertDontSee('المصدر');
    }

    // ---- Search ----

    public function test_search_by_name(): void
    {
        $this->makeCustomer(['name' => 'مؤسسة النجمة', 'whatsapp_number' => '0599000001']);
        $this->makeCustomer(['name' => 'مؤسسة القمر', 'whatsapp_number' => '0599000002']);

        $this->list()->set('search', 'النجمة')->assertSee('مؤسسة النجمة')->assertDontSee('مؤسسة القمر');
    }

    public function test_search_by_customer_code(): void
    {
        $this->makeCustomer(['name' => 'عميل بالرقم', 'customer_number' => 'CUS-91001']);
        $this->makeCustomer(['name' => 'عميل ثانٍ', 'customer_number' => 'CUS-91002']);

        $this->list()->set('search', 'CUS-91001')->assertSee('عميل بالرقم')->assertDontSee('عميل ثانٍ');
    }

    public function test_search_by_whatsapp(): void
    {
        $this->makeCustomer(['name' => 'صاحب واتساب', 'whatsapp_number' => '0599432037']);
        $this->makeCustomer(['name' => 'عميل مختلف', 'whatsapp_number' => '0561234567']);

        $this->list()->set('search', '0599432037')->assertSee('صاحب واتساب')->assertDontSee('عميل مختلف');
    }

    public function test_search_does_not_use_legacy_phone(): void
    {
        // A customer whose only match is the legacy phone must NOT be found.
        $this->makeCustomer(['name' => 'عميل بالهاتف', 'phone' => '0587654321', 'whatsapp_number' => '0599000009']);

        $this->list()->set('search', '0587654321')->assertDontSee('عميل بالهاتف');
    }

    // ---- Filters ----

    public function test_active_filter(): void
    {
        $this->makeCustomer(['name' => 'عميل نشط', 'is_active' => true, 'whatsapp_number' => '0599000001']);
        $this->makeCustomer(['name' => 'عميل معطل', 'is_active' => false, 'whatsapp_number' => '0599000002']);

        $this->list()->set('active', '1')->assertSee('عميل نشط')->assertDontSee('عميل معطل');
    }

    public function test_inactive_filter(): void
    {
        $this->makeCustomer(['name' => 'عميل نشط', 'is_active' => true, 'whatsapp_number' => '0599000001']);
        $this->makeCustomer(['name' => 'عميل معطل', 'is_active' => false, 'whatsapp_number' => '0599000002']);

        $this->list()->set('active', '0')->assertSee('عميل معطل')->assertDontSee('عميل نشط');
    }

    public function test_outstanding_due_filter(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $due = $this->makeCustomer(['name' => 'عميل مدين', 'whatsapp_number' => '0599000001']);
        $this->makeIssuedInvoice($due, '100', '3.00');
        $this->makeCustomer(['name' => 'عميل بلا رصيد', 'whatsapp_number' => '0599000002']);

        Livewire::test(CustomersIndex::class)->set('balance', 'due')
            ->assertSee('عميل مدين')->assertDontSee('عميل بلا رصيد');
    }

    public function test_zero_outstanding_filter(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $due = $this->makeCustomer(['name' => 'عميل مدين', 'whatsapp_number' => '0599000001']);
        $this->makeIssuedInvoice($due, '100', '3.00');
        $this->makeCustomer(['name' => 'عميل بلا رصيد', 'whatsapp_number' => '0599000002']);

        Livewire::test(CustomersIndex::class)->set('balance', 'zero')
            ->assertSee('عميل بلا رصيد')->assertDontSee('عميل مدين');
    }

    public function test_old_filters_are_gone(): void
    {
        $this->list()
            ->assertDontSee('كل التصنيفات')
            ->assertDontSee('كل المصادر')
            ->assertDontSee('المدينة');
    }

    // ---- Financial (per-document ILS) ----

    public function test_outstanding_shows_ils_at_invoice_rate(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->makeIssuedInvoice($customer, '100', '3.00'); // 100 × 3.00 = 300 ₪

        Livewire::test(CustomersIndex::class)
            ->assertSee('$100.00')
            ->assertSee('300.00 ₪');
    }

    public function test_mixed_invoice_rates_sum_per_document(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->makeIssuedInvoice($customer, '100', '3.00'); // 300 ₪
        $this->makeIssuedInvoice($customer, '50', '3.20');  // 160 ₪

        Livewire::test(CustomersIndex::class)
            ->assertSee('$150.00')
            ->assertSee('460.00 ₪')     // 300 + 160, never 150 × one rate
            ->assertDontSee('480.00 ₪'); // 150 × 3.20 (blind rate) must NOT appear
    }

    public function test_draft_invoices_do_not_affect_outstanding(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        // A draft invoice (never issued) must be excluded.
        app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'exchange_rate' => '3.00'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 999]]);

        Livewire::test(CustomersIndex::class)->assertSee('$0.00')->assertDontSee('$999.00');
    }

    public function test_cancelled_invoices_do_not_affect_outstanding(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '200', '3.00');
        app(InvoiceService::class)->cancel($invoice->fresh(), 'إلغاء تجريبي');

        Livewire::test(CustomersIndex::class)->assertSee('$0.00')->assertDontSee('$200.00');
    }

    public function test_partial_payment_reduces_displayed_outstanding(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '100', '3.00');
        $this->payInvoice($customer, $invoice, '40', '3.00'); // remaining 60

        Livewire::test(CustomersIndex::class)
            ->assertSee('$60.00')
            ->assertSee('180.00 ₪'); // 60 × 3.00
    }

    public function test_user_without_finance_permission_sees_no_balance(): void
    {
        // ProjectManager has customers.view but NOT payments.view.
        $pm = $this->makeUser(RoleName::ProjectManager);
        $customer = $this->makeCustomer(['name' => 'عميل مدين', 'whatsapp_number' => '0599432037']);
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $this->makeIssuedInvoice($customer, '100', '3.00');

        Livewire::actingAs($pm)->test(CustomersIndex::class)
            ->assertOk()
            ->assertSee('عميل مدين')
            ->assertDontSee('الرصيد المتبقي')   // no balance column
            ->assertDontSee('إجمالي المستحقات')  // no total-outstanding card
            ->assertDontSee('كل الأرصدة')        // no balance filter
            ->assertDontSee('300.00 ₪');         // no ILS figure leaks
    }

    // ---- Tasks ----

    public function test_task_count_is_correct(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->makeTask(['customer_id' => $customer->id]);
        $this->makeTask(['customer_id' => $customer->id]);
        $this->makeTask(['customer_id' => $customer->id]);

        $this->assertSame(3, Customer::withCount('tasks')->find($customer->id)->tasks_count);
    }

    public function test_task_count_does_not_depend_on_projects(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        // A project with no tasks must not inflate the count.
        $this->makeProject($customer);
        $this->makeTask(['customer_id' => $customer->id]);

        $this->assertSame(1, Customer::withCount('tasks')->find($customer->id)->tasks_count);
    }

    public function test_customer_with_zero_tasks_shows_zero(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->assertSame(0, Customer::withCount('tasks')->find($customer->id)->tasks_count);
    }

    // ---- Actions ----

    public function test_action_icons_and_labels_exist(): void
    {
        $this->makeCustomer(['whatsapp_number' => '0599432037']);

        $this->list()
            ->assertSee('عرض العميل')     // view icon aria-label/title
            ->assertSee('تعديل العميل')   // edit icon
            ->assertSee('تعطيل العميل');  // disable/archive icon
    }

    public function test_inactive_customer_shows_activate_action(): void
    {
        $this->makeCustomer(['name' => 'عميل معطل', 'is_active' => false, 'whatsapp_number' => '0599432037']);

        $this->list()->set('active', '0')->assertSee('تفعيل العميل');
    }

    public function test_no_hard_delete_action_is_present(): void
    {
        $this->makeCustomer(['whatsapp_number' => '0599432037']);

        // The component exposes archive/restore, never a destructive delete.
        $this->assertFalse(method_exists(CustomersIndex::class, 'delete'));
        $this->assertFalse(method_exists(CustomersIndex::class, 'destroy'));
        $this->list()->assertDontSee('حذف');
    }

    // ---- Performance (no N+1) ----

    public function test_list_avoids_n_plus_one_for_tasks_and_balances(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));

        $seed = function (int $count): void {
            for ($i = 0; $i < $count; $i++) {
                $c = $this->makeCustomer(['whatsapp_number' => '059900'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
                $inv = $this->makeIssuedInvoice($c, '100', '3.00');
                $this->makeTask(['customer_id' => $c->id]);
            }
        };

        // Render with a small set …
        $seed(3);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CustomersIndex::class)->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        // … then with many more rows. Query count must stay flat (no per-row query).
        $seed(9);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CustomersIndex::class)->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A true N+1 would add ~9 queries for the 9 extra customers; allow a
        // tiny constant tolerance for unrelated per-render variance only.
        $this->assertLessThanOrEqual($small + 2, $large, "query count grew from {$small} to {$large} — N+1 regression");
    }
}
