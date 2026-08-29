<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\SupplierBalanceService;
use App\Services\SupplierBillService;
use App\Services\SupplierPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SupplierAccountingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function bills(): SupplierBillService
    {
        return app(SupplierBillService::class);
    }

    private function payments(): SupplierPaymentService
    {
        return app(SupplierPaymentService::class);
    }

    private function billJournal(int $billId): JournalEntry
    {
        return JournalEntry::with('lines')->where('source_type', 'supplier_bill')
            ->where('source_id', $billId)->where('posting_type', 'supplier_bill')->firstOrFail();
    }

    private function ap(JournalEntry $entry): ?string
    {
        $line = $entry->lines->firstWhere('account_id', Account::where('code', '2100')->value('id'));

        return $line?->credit_ils ?? $line?->debit_ils;
    }

    public function test_supplier_number_is_generated(): void
    {
        $supplier = $this->makeSupplier();
        $this->assertStringStartsWith('SUP-', $supplier->supplier_number);
    }

    public function test_supplier_bill_creates_expense_and_ap_journal(): void
    {
        // ILS bill 1,000 → Debit Printing 1,000 ; Credit AP 1,000.
        $bill = $this->bills()->createDraft(
            ['supplier_id' => $this->makeSupplier()->id, 'bill_date' => '2026-08-05', 'currency' => 'ILS'],
            [['description' => 'طباعة', 'quantity' => 1, 'unit_price' => '1000', 'tax' => '0', 'expense_account_id' => Account::where('code', '5700')->value('id')]],
        );
        $this->bills()->post($bill);

        $entry = $this->billJournal($bill->id);
        $this->assertSame('1000.00', $entry->lines->firstWhere('account_id', Account::where('code', '5700')->value('id'))->debit_ils);
        $this->assertSame('1000.00', $entry->lines->firstWhere('account_id', Account::where('code', '2100')->value('id'))->credit_ils);
    }

    public function test_supplier_payment_reduces_ap(): void
    {
        $supplier = $this->makeSupplier();
        $bank = $this->makeCashAccount('ILS');
        $bill = $this->bills()->createDraft(
            ['supplier_id' => $supplier->id, 'bill_date' => '2026-08-05', 'currency' => 'ILS'],
            [['description' => 'طباعة', 'quantity' => 1, 'unit_price' => '1000', 'tax' => '0']],
        );
        $this->bills()->post($bill);

        $payment = $this->payments()->createDraft([
            'supplier_id' => $supplier->id, 'currency' => 'ILS', 'amount' => '1000', 'financial_account_id' => $bank->id,
        ]);
        $this->payments()->post($payment, [['bill_id' => $bill->id, 'allocated_original' => '1000']]);

        $entry = JournalEntry::with('lines')->where('source_type', 'supplier_payment')->where('source_id', $payment->id)->firstOrFail();
        // AP debited 1,000 (settled) ; cash credited 1,000.
        $this->assertSame('1000.00', $entry->lines->firstWhere('account_id', Account::where('code', '2100')->value('id'))->debit_ils);
        $this->assertSame('paid', $bill->fresh()->status->value);
    }

    public function test_supplier_outstanding_is_correct(): void
    {
        $supplier = $this->makeSupplier();
        $this->bills()->post($this->bills()->createDraft(
            ['supplier_id' => $supplier->id, 'bill_date' => '2026-08-05', 'currency' => 'ILS'],
            [['description' => 'a', 'quantity' => 1, 'unit_price' => '700']],
        ));

        $this->assertSame('700.00', app(SupplierBalanceService::class)->outstandingIls($supplier->fresh()));
    }

    public function test_supplier_bill_usd_stores_snapshot(): void
    {
        $bill = $this->bills()->createDraft(
            ['supplier_id' => $this->makeSupplier()->id, 'bill_date' => '2026-08-05', 'currency' => 'USD', 'exchange_rate' => '3.30'],
            [['description' => 'hosting', 'quantity' => 1, 'unit_price' => '300']],
        );
        $this->bills()->post($bill);

        $this->assertSame('990.00', $bill->fresh()->total_ils); // 300 × 3.30
        $this->assertSame('990.00', $this->ap($this->billJournal($bill->id)));
    }

    public function test_supplier_payment_fx_difference_is_correct(): void
    {
        // USD bill $300 @ 3.30 → AP 990. Pay $300 @ 3.20 → cash 960 → gain 30.
        $supplier = $this->makeSupplier();
        $usd = $this->makeCashAccount('USD');
        $bill = $this->bills()->createDraft(
            ['supplier_id' => $supplier->id, 'bill_date' => '2026-08-05', 'currency' => 'USD', 'exchange_rate' => '3.30'],
            [['description' => 'hosting', 'quantity' => 1, 'unit_price' => '300']],
        );
        $this->bills()->post($bill);

        $payment = $this->payments()->createDraft([
            'supplier_id' => $supplier->id, 'currency' => 'USD', 'amount' => '300', 'exchange_rate' => '3.20', 'financial_account_id' => $usd->id,
        ]);
        $this->payments()->post($payment, [['bill_id' => $bill->id, 'allocated_original' => '300']]);

        $entry = JournalEntry::with('lines')->where('source_type', 'supplier_payment')->where('source_id', $payment->id)->firstOrFail();
        $this->assertSame('990.00', $entry->lines->firstWhere('account_id', Account::where('code', '2100')->value('id'))->debit_ils);
        $this->assertSame('960.00', $entry->lines->firstWhere('financial_account_id', $usd->id)->credit_ils);
        $this->assertSame('30.00', $entry->lines->firstWhere('account_id', Account::where('code', '4900')->value('id'))->credit_ils); // gain
    }

    public function test_cancelled_supplier_payment_reverses_journal(): void
    {
        $supplier = $this->makeSupplier();
        $bank = $this->makeCashAccount('ILS');
        $bill = $this->bills()->createDraft(
            ['supplier_id' => $supplier->id, 'bill_date' => '2026-08-05', 'currency' => 'ILS'],
            [['description' => 'a', 'quantity' => 1, 'unit_price' => '500']],
        );
        $this->bills()->post($bill);
        $payment = $this->payments()->createDraft([
            'supplier_id' => $supplier->id, 'currency' => 'ILS', 'amount' => '500', 'financial_account_id' => $bank->id,
        ]);
        $this->payments()->post($payment, [['bill_id' => $bill->id, 'allocated_original' => '500']]);

        $this->payments()->cancel($payment->fresh(), $this->makeUser(RoleName::Accountant), 'خطأ');

        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'supplier_payment_reversal', 'source_id' => $payment->id]);
        // Bill returns to open payable.
        $this->assertSame('500.00', $bill->fresh()->remaining_original);
    }
}
