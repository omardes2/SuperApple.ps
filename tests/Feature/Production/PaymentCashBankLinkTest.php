<?php

namespace Tests\Feature\Production;

use App\Enums\FinancialAccountType;
use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\PaymentShow;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Services\FinancialAccountService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * A posted customer payment must land in a real cash/bank account so the
 * /admin/cash-banks balance moves. The receipt journal already debits a cash GL
 * account; the missing link was the operational FinancialAccount (payment
 * account_id), which the UI never captured — so the journal line carried a null
 * financial_account_id and no account balance changed.
 */
class PaymentCashBankLinkTest extends TestCase
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

    private function bankAccount(string $currency = 'ILS'): FinancialAccount
    {
        $gl = Account::where('code', '1130')->firstOrFail(); // البنك (شيكل)

        return app(FinancialAccountService::class)->create([
            'name' => 'بنك اختبار '.$currency.' '.str()->random(4),
            'type' => FinancialAccountType::Bank,
            'currency' => $currency,
            'gl_account_id' => $gl->id,
            'opening_balance' => '0',
            'opening_balance_date' => '2026-07-01',
        ]);
    }

    /** Post a simple ILS payment fully allocated to one invoice, into $account. */
    private function postIls(Customer $customer, FinancialAccount $account, string $ils, string $rate, string $allocUsd, int $invoiceId): void
    {
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => $ils, 'exchange_rate' => $rate, 'account_id' => $account->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoiceId, 'allocated_usd' => $allocUsd]]);
    }

    // 1. ILS payment into a cash account increases its balance (clean-rate case).
    public function test_posted_ils_payment_increases_cash_account_balance(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');

        $this->assertSame('0.00', $this->balances()->balanceIls($cash));
        // 320.00 ILS @ 3.20 = 100.00 USD → cash rises by exactly 320.00.
        $this->postIls($customer, $cash, '320.00', '3.20', '100.00', $invoice->id);

        $this->assertSame('320.00', $this->balances()->balanceIls($cash));
    }

    /**
     * The reported production case (3.00 ILS @ 3.01). The cash line is valued
     * from the allocated USD (1.00) reconverted at the payment rate — the
     * existing accounting valuation — so it records 3.01, not the raw 3.00.
     * The important fix is that the account moves at all (it was stuck at 0.00);
     * the 0.01 is a pre-existing USD-roundtrip rounding we must not "fix" by
     * touching the frozen FX rules. Documented, not asserted as 3.00.
     */
    public function test_reported_case_moves_the_account_off_zero(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.01');
        $cash = $this->makeCashAccount('ILS');

        $this->postIls($customer, $cash, '3.00', '3.01', '1.00', $invoice->id);

        // No longer 0.00 — the operational account now reflects the receipt.
        $this->assertSame('3.01', $this->balances()->balanceIls($cash));
    }

    // 2. ILS payment into a bank account increases its balance.
    public function test_posted_ils_payment_increases_bank_account_balance(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $bank = $this->bankAccount('ILS');

        $this->postIls($customer, $bank, '320.00', '3.20', '100.00', $invoice->id);

        $this->assertSame('320.00', $this->balances()->balanceIls($bank));
    }

    // 1b. The UI fix: selecting the deposit account and posting through the
    //     PaymentShow component moves the operational account balance (the exact
    //     path that was broken — the component never captured account_id).
    public function test_component_post_sets_account_and_moves_balance(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 0,
        ]);

        Livewire::actingAs($gm)->withQueryParams(['invoice' => $invoice->id])
            ->test(PaymentShow::class, ['payment' => $payment])
            ->set('payment_amount', '320.00')
            ->set('exchange_rate', '3.20')
            ->set('account_id', $cash->id)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertSame($cash->id, $payment->fresh()->account_id);
        $this->assertSame('320.00', $this->balances()->balanceIls($cash));
    }

    // 3. A draft payment does NOT change any account balance.
    public function test_draft_payment_does_not_change_account_balance(): void
    {
        $customer = $this->makeCustomer();
        $cash = $this->makeCashAccount('ILS');

        $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '500.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);

        $this->assertSame('0.00', $this->balances()->balanceIls($cash));
    }

    // 4. Posting without a destination account is rejected.
    public function test_payment_cannot_be_posted_without_destination_account(): void
    {
        $customer = $this->makeCustomer();
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '10.00', 'exchange_rate' => '3.20',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('حساب الإيداع');
        $this->service()->post($payment, []);
    }

    // 5. Allocation + cash movement are atomic: a bad allocation rolls everything back.
    public function test_allocation_and_cash_movement_are_atomic(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '5.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);

        // Allocate more than the invoice's remaining → whole post must roll back.
        try {
            $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '10.00']]);
            $this->fail('Expected over-allocation to throw.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('0.00', $this->balances()->balanceIls($cash));         // no cash movement
        $this->assertTrue($payment->fresh()->isDraft());                          // still draft
        $this->assertSame(0, JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->count());
    }

    // 6/7. Cancellation reverses the cash movement AND restores the invoice.
    public function test_cancellation_reverses_cash_movement_and_restores_invoice(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        $this->assertSame('320.00', $this->balances()->balanceIls($cash));
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $this->service()->cancel($payment->fresh(), $gm, 'خطأ إدخال');

        $this->assertSame('0.00', $this->balances()->balanceIls($cash));          // cash reversed
        $invoice->refresh();
        $this->assertSame('100.00', $invoice->remaining_usd);                     // invoice restored
        $this->assertContains($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent]);
    }

    // 8. The payment receipt journal balances (Σ debit = Σ credit).
    public function test_payment_journal_balances(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $this->postIls($customer, $cash, '320.00', '3.20', '100.00', $invoice->id);

        $entry = JournalEntry::where('source_type', 'payment')->where('posting_type', 'payment_receipt')->firstOrFail();
        $this->assertSame(
            (string) $entry->lines()->sum('debit_ils'),
            (string) $entry->lines()->sum('credit_ils'),
        );
        // The cash account line is tagged with the financial account.
        $this->assertSame(1, $entry->lines()->where('financial_account_id', $cash->id)->count());
    }

    // 9. Posting is idempotent — a posted payment cannot be re-posted (no duplicate cash line).
    public function test_no_duplicate_cash_transaction_on_repost(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        try {
            $this->service()->post($payment->fresh(), [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);
            $this->fail('A posted payment must not be re-postable.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('320.00', $this->balances()->balanceIls($cash)); // unchanged
        $this->assertSame(1, JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->where('posting_type', 'payment_receipt')->count());
    }

    // 11. An inactive account cannot receive a payment.
    public function test_inactive_account_cannot_receive_payment(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $cash->update(['is_active' => false]);

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '32.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('غير نشط');
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '10.00']]);
    }

    // 10. Currency mismatch between account and payment is rejected.
    public function test_account_currency_must_match_payment_currency(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $usdCash = $this->makeCashAccount('USD');

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '32.00', 'exchange_rate' => '3.20', 'account_id' => $usdCash->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('لا تطابق عملة الدفعة');
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '10.00']]);
    }

    // 10. Only cash/bank accounts of the matching currency are offered as deposit targets.
    public function test_only_cash_and_bank_accounts_are_offered_as_deposit(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->makeCustomer();
        $cash = $this->makeCashAccount('ILS');
        $bank = $this->bankAccount('ILS');
        $usdCash = $this->makeCashAccount('USD');           // wrong currency
        $card = app(FinancialAccountService::class)->create([ // wrong type
            'name' => 'بطاقة ائتمان', 'type' => FinancialAccountType::CreditCard, 'currency' => 'ILS',
            'gl_account_id' => Account::where('code', '1130')->firstOrFail()->id,
            'opening_balance' => '0', 'opening_balance_date' => '2026-07-01',
        ]);
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '10.00', 'exchange_rate' => '3.20',
        ]);

        $ids = Livewire::actingAs($gm)->test(PaymentShow::class, ['payment' => $payment])
            ->viewData('depositAccounts')->pluck('id');

        $this->assertTrue($ids->contains($cash->id));
        $this->assertTrue($ids->contains($bank->id));
        $this->assertFalse($ids->contains($usdCash->id)); // currency filtered
        $this->assertFalse($ids->contains($card->id));    // type filtered
    }

    // 13. FX gain is still realised correctly, with the cash line at the payment rate.
    public function test_fx_gain_still_correct_with_cash_link(): void
    {
        $customer = $this->makeCustomer();
        // Invoice issued at 3.00; payment later at 3.20 → paid MORE ILS → gain.
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.00');
        $cash = $this->makeCashAccount('ILS');
        $this->postIls($customer, $cash, '320.00', '3.20', '100.00', $invoice->id);

        // Cash received 320 ILS; AR relieved at 300 ILS (invoice rate) → 20 gain.
        $this->assertSame('320.00', $this->balances()->balanceIls($cash));
        $entry = JournalEntry::where('source_type', 'payment')->where('posting_type', 'payment_receipt')->firstOrFail();
        $gainLine = $entry->lines()->whereHas('account', fn ($q) => $q->where('code', '4900'))->first();
        $this->assertNotNull($gainLine);
        $this->assertSame('20.00', Money::money($gainLine->credit_ils));
    }

    // 15. The Cash & Banks page reflects the posted payment immediately.
    public function test_cash_banks_page_shows_updated_balance(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $this->postIls($customer, $cash, '320.00', '3.20', '100.00', $invoice->id);

        $this->get(route('admin.cash-banks'))
            ->assertOk()
            ->assertSee($cash->name)
            ->assertSee('320.00');
    }
}
