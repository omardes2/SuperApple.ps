<?php

namespace Tests\Feature\Production;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\InvoicesIndex;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * UI/filter regression for the redesigned invoices list: quick tabs (derived
 * filters), counts, the Paid column, ILS equivalents, overdue indicator, and
 * that every filter composes. No accounting behaviour is exercised beyond the
 * existing services used to build fixtures.
 *
 * Due dates are set explicitly: makeIssuedInvoice() defaults to a due date that
 * is already in the past relative to the frozen test "today" (2026-09-01), so
 * fixtures pin due_date deliberately to control the computed-overdue state.
 */
class InvoiceListManagementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function comp()
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(InvoicesIndex::class);
    }

    private function setDue(Invoice $invoice, bool $overdue): Invoice
    {
        DB::table('invoices')->where('id', $invoice->id)->update([
            'due_date' => ($overdue ? now()->subDays(3) : now()->addDays(30))->toDateString(),
        ]);

        return $invoice->fresh();
    }

    private function draft(): Invoice
    {
        return app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'invoice_date' => '2026-09-01', 'exchange_rate' => '3.04'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '100.00', 'tax_rate' => 0]],
        );
    }

    /** An issued, unpaid invoice with an explicit overdue/future due date. */
    private function issued(?Customer $customer = null, string $usd = '100.00', string $rate = '3.20', bool $overdue = false): Invoice
    {
        $invoice = $this->makeIssuedInvoice($customer ?? $this->makeCustomer(), $usd, $rate);

        return $this->setDue($invoice, $overdue);
    }

    /** Issue, then allocate a payment; due date future so it is never overdue. */
    private function paid(string $usd, string $allocate, string $rate = '3.20'): Invoice
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, $usd, $rate);
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => sprintf('%.2f', (float) $allocate * (float) $rate),
            'exchange_rate' => $rate, 'account_id' => $cash->id,
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => $allocate]]);

        return $this->setDue($invoice->fresh(), false);
    }

    public function test_page_renders_with_tabs(): void
    {
        $this->comp()->assertOk()
            ->assertSee('الفواتير')
            ->assertSee('الحساب الرسمي للعميل بالدولار الأمريكي (USD)')
            ->assertSee('الكل')->assertSee('غير مدفوعة')->assertSee('مدفوعة جزئياً')
            ->assertSee('مدفوعة')->assertSee('متأخرة')->assertSee('ملغاة');
    }

    public function test_tab_counts_are_correct(): void
    {
        $this->draft();               // draft
        $this->issued();              // unpaid (future due)
        $this->paid('100.00', '40.00'); // partial
        $this->paid('100.00', '100.00'); // paid
        $this->issued(usd: '200.00', overdue: true); // overdue (also unpaid)

        $counts = $this->comp()->viewData('tabCounts');

        $this->assertSame(5, $counts['all']);
        $this->assertSame(1, $counts['draft']);
        $this->assertSame(2, $counts['unpaid']);   // plain unpaid + overdue-unpaid
        $this->assertSame(1, $counts['partial']);
        $this->assertSame(1, $counts['paid']);
        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(0, $counts['cancelled']);
    }

    public function test_all_tab_shows_everything(): void
    {
        $this->draft();
        $this->issued();

        $this->comp()->set('tab', 'all')->assertViewHas('invoices', fn ($p) => $p->total() === 2);
    }

    public function test_draft_tab_filters(): void
    {
        $d = $this->draft();
        $this->issued();

        $this->comp()->call('selectTab', 'draft')
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $d->id);
    }

    public function test_unpaid_tab_filters(): void
    {
        $u = $this->issued();
        $this->paid('100.00', '100.00'); // paid — excluded

        $this->comp()->call('selectTab', 'unpaid')
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $u->id);
    }

    public function test_partial_tab_filters(): void
    {
        $partial = $this->paid('100.00', '40.00');
        $this->issued(); // unpaid

        $this->comp()->call('selectTab', 'partial')
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $partial->id);
    }

    public function test_paid_tab_filters(): void
    {
        $paid = $this->paid('100.00', '100.00');
        $this->issued();

        $this->comp()->call('selectTab', 'paid')
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $paid->id);
    }

    public function test_overdue_tab_filters(): void
    {
        $od = $this->issued(usd: '200.00', overdue: true);
        $this->issued(); // future due — not overdue

        $this->comp()->call('selectTab', 'overdue')
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $od->id);
    }

    public function test_cancelled_tab_filters(): void
    {
        $inv = $this->issued();
        app(InvoiceService::class)->cancel($inv, 'خطأ');
        $this->issued();

        $this->comp()->call('selectTab', 'cancelled')
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $inv->id);
    }

    public function test_search_and_tab_compose(): void
    {
        $target = $this->makeCustomer(['name' => 'شركة ألفا']);
        $inv = $this->issued($target);
        $this->issued($this->makeCustomer(['name' => 'بيتا']));

        $this->comp()->set('tab', 'unpaid')->set('search', 'ألفا')
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $inv->id);
    }

    public function test_customer_filter_and_tab_compose(): void
    {
        $c = $this->makeCustomer();
        $mine = $this->issued($c);
        $this->issued();

        $this->comp()->set('tab', 'unpaid')->set('customer', (string) $c->id)
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1 && $p->first()->id === $mine->id);
    }

    public function test_total_paid_remaining_and_ils_displayed(): void
    {
        // $100 @ 3.04, pay $40 → paid 40, remaining 60; ILS at invoice rate.
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.04');
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '121.60', 'exchange_rate' => '3.04', 'account_id' => $cash->id,
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => '40.00']]);

        $this->comp()
            ->assertSee('$100.00')   // total
            ->assertSee('$40.00')    // paid
            ->assertSee('$60.00')    // remaining
            ->assertSee('304.00')    // total ILS (100 × 3.04)
            ->assertSee('182.40');   // remaining ILS (60 × 3.04)
    }

    public function test_customer_null_safe(): void
    {
        app(InvoiceService::class)->createDraft(
            ['customer_id' => null, 'invoice_date' => '2026-09-01', 'exchange_rate' => '3.04'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '10.00', 'tax_rate' => 0]],
        );

        $this->comp()->assertOk()->assertSee('بلا عميل');
    }

    public function test_overdue_indicator_shown(): void
    {
        $this->issued(usd: '200.00', overdue: true);
        $this->comp()->assertSee('متأخرة');
    }

    public function test_record_payment_action_appears_and_hidden_for_paid(): void
    {
        $this->issued(); // open
        $this->comp()->set('tab', 'unpaid')->assertSee('تسجيل دفعة عن الفاتورة');

        $this->paid('50.00', '50.00'); // fully paid
        $this->comp()->set('tab', 'paid')->assertDontSee('تسجيل دفعة عن الفاتورة');
    }

    public function test_export_streams_csv_for_authorized_user(): void
    {
        $this->issued($this->makeCustomer(['name' => 'عميل تصدير']));

        $response = $this->comp()->call('export');
        $response->assertFileDownloaded();
    }

    public function test_perpage_controls_page_size(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->issued(usd: '10.00');
        }

        $this->comp()->set('perPage', 15)->assertViewHas('invoices', fn ($p) => $p->perPage() === 15 && $p->count() === 15);
    }

    public function test_no_n_plus_one_on_render(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->paid('20.00', '10.00'); // issued + partially paid, distinct customers
        }

        DB::enableQueryLog();
        $this->comp()->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Eager loading + aggregate counts keep this bounded regardless of rows.
        $this->assertLessThan(30, $count, "Query count {$count} suggests an N+1 regression");
    }

    public function test_status_select_still_filters(): void
    {
        $this->draft();
        $this->issued();

        $this->comp()->set('status', InvoiceStatus::Draft->value)
            ->assertViewHas('invoices', fn ($p) => $p->total() === 1);
    }
}
