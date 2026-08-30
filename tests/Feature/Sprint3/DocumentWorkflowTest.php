<?php

namespace Tests\Feature\Sprint3;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\InvoiceShow;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Invoice draft-editing workflow. (Quotations were retired — invoices are now
 * created directly from the customer, so the quotation lifecycle tests were
 * removed; the shared line-editor behaviour is exercised here on invoices.)
 */
class DocumentWorkflowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function draftInvoice(): Invoice
    {
        return app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.30'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100, 'tax_rate' => 0]]);
    }

    public function test_draft_invoice_can_be_edited_via_component(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = $this->draftInvoice();

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('lines.0.unit_price_usd', 250)
            ->set('lines.0.quantity', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('500.00', $invoice->fresh()->total_usd);
    }

    public function test_negative_price_and_quantity_are_rejected(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = $this->draftInvoice();

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('lines.0.unit_price_usd', -10)
            ->call('save')
            ->assertHasErrors('lines.0.unit_price_usd');

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('lines.0.quantity', 0)
            ->call('save')
            ->assertHasErrors('lines.0.quantity');
    }

    public function test_invoice_draft_editor_can_change_exchange_rate_before_issue(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.30'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 1000]]);

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('exchange_rate', '3.50')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('3.500000', $invoice->fresh()->exchange_rate);
    }

    public function test_issue_through_component_locks_the_invoice(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.28'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 2000, 'tax_rate' => 0]]);

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->call('issue')
            ->assertHasNoErrors();

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame('6560.00', $fresh->total_ils_at_issue);
    }
}
