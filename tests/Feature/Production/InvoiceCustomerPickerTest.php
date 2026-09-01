<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\InvoiceShow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The invoice form picks the customer through a searchable combobox (name /
 * number / whatsapp) instead of one long select. Only a draft may change its
 * customer; an issued invoice's customer is locked.
 */
class InvoiceCustomerPickerTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function draftInvoice(?Customer $customer = null): Invoice
    {
        $customer ??= $this->makeCustomer();

        return app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-01', 'exchange_rate' => '3.20'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '100.00', 'tax_rate' => 0]],
        );
    }

    private function comp(Invoice $invoice)
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoiceShow::class, ['invoice' => $invoice]);
    }

    public function test_search_field_is_shown_when_no_customer_selected(): void
    {
        $invoice = $this->draftInvoice();
        $this->comp($invoice)
            ->set('customer_id', null)
            ->assertSee('ابحث بالاسم / رقم العميل / واتساب...');
    }

    public function test_search_matches_by_name(): void
    {
        $invoice = $this->draftInvoice();
        $target = $this->makeCustomer(['name' => 'أزياء الملكة', 'customer_number' => 'CUS-80001']);

        $results = $this->comp($invoice)
            ->set('customer_id', null)
            ->set('customerSearch', 'الملكة')
            ->viewData('customerResults');

        $this->assertTrue($results->contains('id', $target->id));
    }

    public function test_search_matches_by_number_and_whatsapp(): void
    {
        $invoice = $this->draftInvoice();
        $target = $this->makeCustomer(['name' => 'براء', 'customer_number' => 'CUS-80055', 'whatsapp_number' => '970599123456']);

        $byNumber = $this->comp($invoice)->set('customer_id', null)
            ->set('customerSearch', '80055')->viewData('customerResults');
        $this->assertTrue($byNumber->contains('id', $target->id));

        $byWhats = $this->comp($invoice)->set('customer_id', null)
            ->set('customerSearch', '599123456')->viewData('customerResults');
        $this->assertTrue($byWhats->contains('id', $target->id));
    }

    public function test_results_are_capped_at_ten(): void
    {
        $invoice = $this->draftInvoice();
        for ($i = 0; $i < 15; $i++) {
            $this->makeCustomer(['name' => "عميل بحث {$i}"]);
        }

        $results = $this->comp($invoice)->set('customer_id', null)
            ->set('customerSearch', 'عميل بحث')->viewData('customerResults');

        $this->assertLessThanOrEqual(10, $results->count());
    }

    public function test_selecting_a_customer_sets_it_and_clears_search(): void
    {
        $invoice = $this->draftInvoice();
        $target = $this->makeCustomer(['name' => 'عميل مختار']);

        $this->comp($invoice)
            ->set('customer_id', null)
            ->set('customerSearch', 'مختار')
            ->call('selectCustomer', $target->id)
            ->assertSet('customer_id', $target->id)
            ->assertSet('customerSearch', '')
            ->assertSee('عميل مختار');
    }

    public function test_clear_customer_resets_selection(): void
    {
        $invoice = $this->draftInvoice();

        $this->comp($invoice)
            ->call('clearCustomer')
            ->assertSet('customer_id', null)
            ->assertSee('ابحث بالاسم / رقم العميل / واتساب...');
    }

    public function test_selected_customer_persists_on_save(): void
    {
        $invoice = $this->draftInvoice();
        $target = $this->makeCustomer(['name' => 'عميل محفوظ']);

        $this->comp($invoice)
            ->call('selectCustomer', $target->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($target->id, $invoice->fresh()->customer_id);
    }

    public function test_issued_invoice_customer_is_read_only(): void
    {
        $customer = $this->makeCustomer(['name' => 'عميل صادر']);
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $other = $this->makeCustomer(['name' => 'محاولة تغيير']);

        // An issued invoice shows the read-only data card, not the picker, and
        // the customer cannot be switched.
        $this->comp($invoice->fresh())
            ->call('selectCustomer', $other->id)
            ->assertSet('customer_id', $customer->id)
            ->assertDontSee('ابحث بالاسم / رقم العميل / واتساب...');
    }
}
