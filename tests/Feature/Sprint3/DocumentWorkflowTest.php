<?php

namespace Tests\Feature\Sprint3;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\InvoiceShow;
use App\Livewire\Admin\QuotationShow;
use App\Models\Quotation;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function draftQuotation(): Quotation
    {
        return app(QuotationService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);
    }

    public function test_draft_quotation_can_be_edited_via_component(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftQuotation();

        Livewire::actingAs($gm)->test(QuotationShow::class, ['quotation' => $q])
            ->set('lines.0.unit_price_usd', 250)
            ->set('lines.0.quantity', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('500.00', $q->fresh()->total_usd);
    }

    public function test_sent_quotation_cannot_be_silently_edited(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftQuotation();
        app(QuotationService::class)->send($q);

        // Service layer refuses to edit a non-draft.
        $this->expectException(RuntimeException::class);
        app(QuotationService::class)->updateDraft($q->fresh(), [], [['item_name' => 'x', 'quantity' => 1, 'unit_price_usd' => 999]]);
    }

    public function test_sent_quotation_can_be_duplicated_as_revision(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftQuotation();
        app(QuotationService::class)->send($q);

        $revision = app(QuotationService::class)->duplicateAsRevision($q->fresh());

        $this->assertSame(QuotationStatus::Draft, $revision->status);
        $this->assertSame($q->id, $revision->revision_of);
        $this->assertSame(1, $revision->items()->count());
        // The original is untouched.
        $this->assertSame(QuotationStatus::Sent, $q->fresh()->status);
    }

    public function test_negative_price_and_quantity_are_rejected(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftQuotation();

        Livewire::actingAs($gm)->test(QuotationShow::class, ['quotation' => $q])
            ->set('lines.0.unit_price_usd', -10)
            ->call('save')
            ->assertHasErrors('lines.0.unit_price_usd');

        Livewire::actingAs($gm)->test(QuotationShow::class, ['quotation' => $q])
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
