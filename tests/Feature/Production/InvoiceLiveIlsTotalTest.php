<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\InvoiceShow;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * While editing a draft invoice, the totals card shows the ILS equivalent live
 * from the rate being typed (display only — the accounting value is still USD
 * and is frozen at issue).
 */
class InvoiceLiveIlsTotalTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function draft(): Invoice
    {
        return app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'invoice_date' => '2026-09-01'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '1.00', 'tax_rate' => 0]],
        );
    }

    private function comp(Invoice $invoice)
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoiceShow::class, ['invoice' => $invoice]);
    }

    public function test_no_ils_shown_before_a_rate_is_entered(): void
    {
        $this->comp($this->draft())->assertSet('exchange_rate', null)->assertDontSee('₪');
    }

    public function test_ils_total_appears_live_when_rate_typed(): void
    {
        // $1.00 × 3 = 3.00 ₪, shown as soon as the rate is set — no save needed.
        $this->comp($this->draft())
            ->set('exchange_rate', '3')
            ->assertSee('3.00 ₪');
    }

    public function test_ils_total_updates_when_rate_changes(): void
    {
        $this->comp($this->draft())
            ->set('exchange_rate', '3.50')
            ->assertSee('3.50 ₪')      // 1.00 × 3.50
            ->set('exchange_rate', '4')
            ->assertSee('4.00 ₪')
            ->assertDontSee('3.50 ₪');
    }
}
