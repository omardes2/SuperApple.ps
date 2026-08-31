<?php

namespace Tests\Feature\Production;

use App\Enums\FinancialAccountType;
use App\Enums\RoleName;
use App\Livewire\Admin\PaymentShow;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Services\FinancialAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The deposit account ("إيداع في") must always match the payment currency: a USD
 * payment only offers USD accounts, an ILS payment only ILS accounts, switching
 * the currency clears a now-mismatched selection, and the backend refuses a
 * mismatched account even if the request is tampered with. An ILS payment can
 * still settle a USD invoice — the cash lands in ILS while the allocation is in
 * the invoice's USD.
 */
class PaymentCurrencyAccountTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function balances(): FinancialAccountService
    {
        return app(FinancialAccountService::class);
    }

    private function bankIls(): FinancialAccount
    {
        $gl = Account::where('code', '1130')->firstOrFail();

        return app(FinancialAccountService::class)->create([
            'name' => 'حساب البنك شيكل', 'type' => FinancialAccountType::Bank, 'currency' => 'ILS',
            'gl_account_id' => $gl->id, 'opening_balance' => '0', 'opening_balance_date' => '2026-07-01',
        ]);
    }

    /** A draft opened in the PaymentShow component for the given currency. */
    private function draftComponent(string $currency)
    {
        $customer = $this->makeCustomer();
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => $currency, 'payment_amount' => 0,
        ]);

        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment]);
    }

    // 1 + 4. USD payment → only USD accounts (ILS hidden).
    public function test_usd_payment_shows_only_usd_accounts(): void
    {
        $ilsCash = $this->makeCashAccount('ILS');
        $ilsBank = $this->bankIls();
        $usdCash = $this->makeCashAccount('USD');

        $ids = $this->draftComponent('USD')->viewData('depositAccounts')->pluck('id');

        $this->assertTrue($ids->contains($usdCash->id));
        $this->assertFalse($ids->contains($ilsCash->id));
        $this->assertFalse($ids->contains($ilsBank->id));
    }

    // 2 + 3. ILS payment → only ILS accounts (USD hidden), including the ILS bank.
    public function test_ils_payment_shows_only_ils_accounts(): void
    {
        $ilsCash = $this->makeCashAccount('ILS');
        $ilsBank = $this->bankIls();
        $usdCash = $this->makeCashAccount('USD');

        $ids = $this->draftComponent('ILS')->viewData('depositAccounts')->pluck('id');

        $this->assertTrue($ids->contains($ilsCash->id));
        $this->assertTrue($ids->contains($ilsBank->id));
        $this->assertFalse($ids->contains($usdCash->id));
    }

    // 5. Switching USD → ILS clears a selected USD account.
    public function test_switching_usd_to_ils_clears_selected_usd_account(): void
    {
        $usdCash = $this->makeCashAccount('USD');
        $this->makeCashAccount('ILS');

        $this->draftComponent('USD')
            ->set('account_id', $usdCash->id)
            ->assertSet('account_id', $usdCash->id)
            ->set('payment_currency', 'ILS')
            ->assertSet('account_id', null);
    }

    // 6. Switching ILS → USD clears a selected ILS account.
    public function test_switching_ils_to_usd_clears_selected_ils_account(): void
    {
        $ilsCash = $this->makeCashAccount('ILS');
        $this->makeCashAccount('USD');

        $this->draftComponent('ILS')
            ->set('account_id', $ilsCash->id)
            ->assertSet('account_id', $ilsCash->id)
            ->set('payment_currency', 'USD')
            ->assertSet('account_id', null);
    }

    // 7. The backend refuses a currency-mismatched account (tampered request).
    public function test_backend_rejects_currency_mismatched_account_on_save(): void
    {
        $customer = $this->makeCustomer();
        $usdCash = $this->makeCashAccount('USD');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS', 'payment_amount' => 0,
        ]);

        // Force a mismatched account past the filtered UI, then try to save.
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment])
            ->set('payment_amount', '527.00')
            ->set('exchange_rate', '3.10')
            ->set('account_id', $usdCash->id)
            ->call('save')
            ->assertHasErrors('account_id');
    }

    // 7b. The service is the final gate even if the component is bypassed.
    public function test_service_post_rejects_currency_mismatch(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $usdCash = $this->makeCashAccount('USD');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '527.00', 'exchange_rate' => '3.10', 'account_id' => $usdCash->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('لا تطابق عملة الدفعة');
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '170.00']]);
    }

    // 8 + 9. ILS payment against a USD invoice: 527 ILS / 3.10 = 170 USD equivalent.
    public function test_ils_payment_against_usd_invoice_computes_usd_equivalent(): void
    {
        $customer = $this->makeCustomer();
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '527.00', 'exchange_rate' => '3.10',
        ]);

        $this->assertSame('170.00', $payment->usd_equivalent); // 527 / 3.10
    }

    // 9 + 10 + 11. Cash lands as 527 ILS; the USD invoice's remaining drops by 170.
    public function test_ils_payment_moves_ils_cash_and_reduces_usd_invoice(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10'); // 170 USD invoice
        $ilsCash = $this->makeCashAccount('ILS');
        $this->assertSame('170.00', $invoice->remaining_usd);

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '527.00', 'exchange_rate' => '3.10', 'account_id' => $ilsCash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '170.00']]);

        // Actual cash in the ILS account is the ILS the customer paid.
        $this->assertSame('527.00', $this->balances()->balanceIls($ilsCash));
        // The USD invoice is settled by the 170 USD allocation.
        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);
    }

    // 12. An ILS bank account can receive an ILS payment.
    public function test_ils_bank_account_receives_ils_payment(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $bank = $this->bankIls();

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '527.00', 'exchange_rate' => '3.10', 'account_id' => $bank->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '170.00']]);

        $this->assertSame('527.00', $this->balances()->balanceIls($bank));
    }

    // 13. A USD cash account can receive a USD payment (no conversion).
    public function test_usd_cash_account_receives_usd_payment(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $usdCash = $this->makeCashAccount('USD');

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            // A USD payment needs no conversion for its USD equivalent; the rate is
            // only the ILS accounting valuation of the cash line (frozen FX rules).
            'payment_amount' => '170.00', 'exchange_rate' => '3.10', 'account_id' => $usdCash->id,
        ]);
        $this->assertSame('170.00', $payment->usd_equivalent);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '170.00']]);

        // The USD account's own-currency balance rises by the 170 USD received.
        $this->assertSame('170.00', $this->balances()->balanceOriginal($usdCash));
        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);
    }

    // 5b (UI). The empty-state message appears when no account exists for the currency.
    public function test_message_shown_when_no_account_for_currency(): void
    {
        $this->makeCashAccount('ILS'); // only an ILS account exists

        // A USD payment has no matching account → clear guidance, no options.
        $this->draftComponent('USD')
            ->assertSee('لا يوجد حساب نقدي/بنكي نشط بعملة USD')
            ->viewData('depositAccounts');
    }
}
