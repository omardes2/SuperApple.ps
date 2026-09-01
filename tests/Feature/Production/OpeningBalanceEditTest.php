<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomerProfile;
use App\Models\CustomerOpeningBalance;
use App\Services\CustomerOpeningBalanceService;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Editing a posted opening balance is not an in-place edit — it reverses the
 * existing document (mirror journal, kept on record) and posts a corrected one.
 * The corrected figure becomes the single live balance and AR stays balanced.
 */
class OpeningBalanceEditTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function service(): CustomerOpeningBalanceService
    {
        return app(CustomerOpeningBalanceService::class);
    }

    public function test_replace_reverses_old_and_posts_corrected_balance(): void
    {
        $customer = $this->makeCustomer();
        $old = $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $new = $this->service()->replace($old, [
            'type' => 'debit', 'amount_usd' => '750', 'exchange_rate' => '3.20', 'balance_date' => '2026-01-05',
        ], $this->makeUser(RoleName::Accountant), 'تصحيح القيمة');

        // Old is reversed and kept; new is the single live posted balance.
        $this->assertSame(CustomerOpeningBalance::STATUS_REVERSED, $old->fresh()->status);
        $this->assertDatabaseHas('customer_opening_balances', ['id' => $old->id]);
        $this->assertSame(CustomerOpeningBalance::STATUS_POSTED, $new->status);
        $this->assertSame('750.00', $new->amount_usd);
        $this->assertSame('2400.00', $new->amount_ils); // 750 × 3.20
        $this->assertSame('750.00', $new->remaining_usd);

        $this->assertSame(1, $customer->openingBalances()->posted()->count());
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
    }

    public function test_replace_is_blocked_when_a_payment_was_allocated(): void
    {
        $customer = $this->makeCustomer();
        $ob = $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->makeCashAccount('ILS')->id, 'customer_id' => $customer->id,
            'payment_currency' => 'ILS', 'payment_amount' => 310, 'exchange_rate' => '3.10', 'payment_date' => '2026-02-01',
        ]);
        app(PaymentService::class)->post($payment, [['opening_balance_id' => $ob->id, 'allocated_usd' => 100]]);

        $this->expectException(\RuntimeException::class);
        $this->service()->replace($ob->fresh(), [
            'type' => 'debit', 'amount_usd' => '500', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ], $this->makeUser(RoleName::Accountant));
    }

    public function test_livewire_edit_flow_prefills_and_replaces(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer();
        $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        Livewire::test(CustomerProfile::class, ['customer' => $customer])
            ->call('openEditOpeningBalance')
            ->assertSet('obEditing', true)
            ->assertSet('obAmountUsd', '1000.00')
            ->assertSet('obType', 'debit')
            ->set('obAmountUsd', '640')
            ->set('obRate', '3.15')
            ->call('saveOpeningBalance')
            ->assertHasNoErrors()
            ->assertSet('showOpeningBalance', false);

        $live = $customer->openingBalances()->posted()->latest('id')->first();
        $this->assertSame('640.00', $live->amount_usd);
        $this->assertSame(1, $customer->openingBalances()->posted()->count());
        $this->assertSame(1, $customer->openingBalances()->where('status', CustomerOpeningBalance::STATUS_REVERSED)->count());
    }
}
