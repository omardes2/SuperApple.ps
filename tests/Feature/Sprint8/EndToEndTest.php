<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use App\Models\Payment;
use App\Services\AccountingReportService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\ReconciliationService;
use App\Services\SubscriptionBillingService;
use App\Services\SupplierBillService;
use App\Services\SupplierPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * End-to-end business journeys that cross module boundaries, each asserting the
 * accounting stays balanced and reconciled — the core promise of the system.
 */
class EndToEndTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function assertBooksBalanced(): void
    {
        $tb = app(AccountingReportService::class)->trialBalance();
        $this->assertSame($tb['totals']['ending_debit'], $tb['totals']['ending_credit'], 'Trial balance must tie out');
        $this->assertTrue(app(AccountingReportService::class)->balanceSheet()['balanced'], 'Balance sheet must balance');
    }

    public function test_scenario_1_customer_invoice_payment_reconciles_ar(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.30');

        // Pay in full (ILS) and allocate to the invoice.
        $payment = app(PaymentService::class)->createDraft(['account_id' => $this->cashAccount('ILS')->id,
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '3300', 'exchange_rate' => '3.30', 'payment_date' => '2026-08-05',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '1000']]);

        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
        $this->assertBooksBalanced();
    }

    public function test_scenario_2_subscription_bills_and_gets_paid_to_zero(): void
    {
        $this->seedExchangeRate('2026-08-01', '3.60');
        $customer = $this->makeCustomer();
        $sub = $this->makeActiveSubscription($customer, ['auto_issue_invoice' => true, 'start_date' => '2026-08-01'],
            [['item_name' => 'باقة', 'quantity' => 1, 'unit_price_usd' => '600', 'tax_rate' => 0]]);

        app(SubscriptionBillingService::class)->billOne($sub->id, '2026-08-01');
        $invoice = $sub->invoices()->firstOrFail();
        $this->assertSame('issued', $invoice->status->value);

        $payment = app(PaymentService::class)->createDraft(['account_id' => $this->cashAccount('USD')->id,
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => '600', 'exchange_rate' => '3.60', 'payment_date' => '2026-08-05',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '600']]);

        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
        $this->assertBooksBalanced();
    }

    public function test_scenario_3_payroll_reconciles_salary_payable(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());

        $this->assertTrue(app(ReconciliationService::class)->salaryPayable()['balanced']);
        $this->assertBooksBalanced();
    }

    public function test_scenario_4_supplier_bill_payment_reconciles_ap(): void
    {
        $supplier = $this->makeSupplier();
        $bank = $this->makeCashAccount('ILS', '10000');
        $bill = app(SupplierBillService::class)->createDraft(
            ['supplier_id' => $supplier->id, 'bill_date' => '2026-08-05', 'currency' => 'ILS'],
            [['description' => 'طباعة', 'quantity' => 1, 'unit_price' => '1000', 'tax' => '0']],
        );
        app(SupplierBillService::class)->post($bill);

        $payment = app(SupplierPaymentService::class)->createDraft([
            'supplier_id' => $supplier->id, 'currency' => 'ILS', 'amount' => '1000', 'financial_account_id' => $bank->id,
        ]);
        app(SupplierPaymentService::class)->post($payment, [['bill_id' => $bill->id, 'allocated_original' => '1000']]);

        $this->assertSame('paid', $bill->fresh()->status->value);
        $this->assertTrue(app(ReconciliationService::class)->accountsPayable()['balanced']);
        $this->assertBooksBalanced();
    }

    public function test_scenario_5_cancelled_payment_restores_invoice_and_gl(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.30');

        $payment = app(PaymentService::class)->createDraft(['account_id' => $this->cashAccount('USD')->id,
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => '1000', 'exchange_rate' => '3.30', 'payment_date' => '2026-08-05',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '1000']]);
        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);

        // Cancel the payment → allocation reversed, invoice balance restored, GL balanced.
        app(PaymentService::class)->cancel($payment->fresh(), $this->makeUser(RoleName::GeneralManager), 'خطأ في التسجيل');

        $this->assertSame('1000.00', $invoice->fresh()->remaining_usd);
        $this->assertSame('cancelled', $payment->fresh()->status->value);
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
        $this->assertBooksBalanced();
    }
}
