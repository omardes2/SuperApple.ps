<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Models\Account;
use App\Services\AccountingReportService;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use App\Services\SupplierBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
        $this->buildScenario();
    }

    /** A realistic mix: invoice + partial ILS payment (FX), an expense, a supplier bill. */
    private function buildScenario(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20'); // AR 3,200
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30', 'account_id' => $this->makeCashAccount('ILS')->id,
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $bank = $this->makeCashAccount('ILS', '10000');
        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $this->expenseCategory()->id, 'description' => 'إيجار',
            'currency' => 'ILS', 'amount' => '1000', 'financial_account_id' => $bank->id,
        ]);
        app(ExpenseService::class)->post($expense);

        app(SupplierBillService::class)->post(app(SupplierBillService::class)->createDraft(
            ['supplier_id' => $this->makeSupplier()->id, 'bill_date' => '2026-08-05', 'currency' => 'ILS'],
            [['description' => 'a', 'quantity' => 1, 'unit_price' => '800']],
        ));
    }

    private function reports(): AccountingReportService
    {
        return app(AccountingReportService::class);
    }

    public function test_trial_balance_debits_equal_credits(): void
    {
        $tb = $this->reports()->trialBalance();
        $this->assertSame($tb['totals']['ending_debit'], $tb['totals']['ending_credit']);
    }

    public function test_general_ledger_running_balance_is_correct(): void
    {
        $arId = Account::where('code', '1200')->value('id');
        $gl = $this->reports()->generalLedger($arId);

        // AR: +3,200 (issue) then −3,200 (payment) → closing 0.
        $this->assertSame('0.00', $gl['closing']);
        $this->assertNotEmpty($gl['rows']);
    }

    public function test_profit_and_loss_revenue_is_correct(): void
    {
        $pl = $this->reports()->profitAndLoss();
        // Revenue = invoice 3,200 + exchange gain 100 = 3,300.
        $this->assertSame('3300.00', $pl['total_revenue']);
    }

    public function test_expenses_are_reflected_in_pl(): void
    {
        $pl = $this->reports()->profitAndLoss();
        // Rent expense 1,000 + supplier bill 800 (booked to expense) = 1,800.
        $this->assertSame('1800.00', $pl['total_expense']);
    }

    public function test_exchange_gain_loss_shown_separately(): void
    {
        $pl = $this->reports()->profitAndLoss();
        $names = collect($pl['revenue'])->map(fn ($r) => $r['account']->name);
        $this->assertTrue($names->contains(fn ($n) => str_contains($n, 'فروقات')));
    }

    public function test_balance_sheet_balances(): void
    {
        $bs = $this->reports()->balanceSheet();
        $this->assertTrue($bs['balanced'], 'Balance sheet must balance: '.$bs['total_assets'].' vs '.$bs['total_liabilities_equity']);
    }

    public function test_ar_reconciliation_passes(): void
    {
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
    }

    public function test_ap_reconciliation_passes(): void
    {
        $this->assertTrue(app(ReconciliationService::class)->accountsPayable()['balanced']);
    }

    public function test_cash_reconciliation_passes(): void
    {
        $this->assertTrue(app(ReconciliationService::class)->cash()['balanced']);
    }
}
