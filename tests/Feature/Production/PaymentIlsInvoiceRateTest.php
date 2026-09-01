<?php

namespace Tests\Feature\Production;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Enums\SystemAccountKey;
use App\Livewire\Admin\PaymentShow;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\CustomerOpeningBalanceService;
use App\Services\FinancialAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * An ILS payment carries no manual exchange rate: it converts at the rate of the
 * invoice (or opening balance) it settles. Because the payment rate equals the
 * invoice rate, the AR relief in ILS equals the shekels received, there is no FX
 * gain/loss, and the cash account rises by exactly the shekels paid.
 */
class PaymentIlsInvoiceRateTest extends TestCase
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

    public function test_ils_payment_without_manual_rate_posts_at_invoice_rate(): void
    {
        $customer = $this->makeCustomer();
        // Invoice: 100 USD @ 3.20 → 320 ILS due.
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');

        // Customer pays 320 ILS. No exchange_rate provided.
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        $payment->refresh();
        // Rate was derived from the invoice (3.20) and USD equivalent computed.
        $this->assertSame('3.200000', $payment->exchange_rate);
        $this->assertSame('100.00', $payment->usd_equivalent);
        // Invoice fully paid; cash rose by exactly the shekels received.
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('320.00', $this->balances()->balanceIls($cash));
    }

    public function test_no_fx_gain_or_loss_on_invoice_rate_payment(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        $entry = JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->with('lines')->firstOrFail();
        $gainId = app(AccountingService::class)->systemAccountId(SystemAccountKey::ExchangeGain);
        $lossId = app(AccountingService::class)->systemAccountId(SystemAccountKey::ExchangeLoss);
        $this->assertSame(0, $entry->lines->whereIn('account_id', [$gainId, $lossId])->count());
        // Balanced journal.
        $this->assertSame((float) $entry->lines->sum('debit_ils'), (float) $entry->lines->sum('credit_ils'));
    }

    public function test_ils_payment_partially_settles_invoice_at_its_rate(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.10'); // 310 ILS due
        $cash = $this->makeCashAccount('ILS');

        // Pay 155 ILS → 50 USD at the invoice rate.
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '155.00', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '50.00']]);

        $this->assertSame('50.00', $invoice->fresh()->remaining_usd);
        $this->assertSame('155.00', $this->balances()->balanceIls($cash));
    }

    public function test_ils_payment_without_allocation_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '200.00', 'account_id' => $cash->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('يجب تخصيصها على فاتورة');
        $this->service()->post($payment, []);
    }

    public function test_ils_payment_derives_rate_from_opening_balance(): void
    {
        $customer = $this->makeCustomer();
        app(CustomerOpeningBalanceService::class)->create($customer, [
            'type' => 'debit', 'amount_usd' => '100.00', 'exchange_rate' => '3.50', 'balance_date' => '2026-01-01',
        ]);
        $ob = $customer->postedOpeningBalance();
        $cash = $this->makeCashAccount('ILS');

        // 175 ILS ÷ 3.50 = 50 USD against the opening balance.
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '175.00', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['opening_balance_id' => $ob->id, 'allocated_usd' => '50.00']]);

        $this->assertSame('3.500000', $payment->fresh()->exchange_rate);
        $this->assertSame('175.00', $this->balances()->balanceIls($cash));
    }

    public function test_manual_rate_is_still_honoured_when_provided(): void
    {
        // Backward compatibility: an explicit rate is used as before.
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.00'); // invoice at 3.00
        $cash = $this->makeCashAccount('ILS');

        // Pay at a DIFFERENT manual rate 3.20 → realises FX vs the invoice's 3.00.
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        $this->assertSame('3.200000', $payment->fresh()->exchange_rate); // manual rate kept
        $this->assertSame('320.00', $this->balances()->balanceIls($cash));
    }

    public function test_component_autoallocate_posts_ils_at_invoice_rate(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS', 'payment_amount' => 0,
        ]);

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment])
            ->set('payment_amount', '320.00')
            ->set('account_id', $cash->id)
            ->call('autoAllocate')
            ->call('post')
            ->assertHasNoErrors();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('320.00', $this->balances()->balanceIls($cash));
    }
}
