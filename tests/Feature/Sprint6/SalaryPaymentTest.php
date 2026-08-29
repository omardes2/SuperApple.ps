<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Services\PayrollPaymentService;
use App\Services\PayrollService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SalaryPaymentTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected FinancialAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $this->bank = $this->makeCashAccount('ILS', '50000');
    }

    private function postedRun(string $salary = '4000'): PayrollRun
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, $salary);
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));

        return app(PayrollService::class)->post($run->fresh());
    }

    private function service(): PayrollPaymentService
    {
        return app(PayrollPaymentService::class);
    }

    public function test_salary_payment_reduces_salary_payable(): void
    {
        $run = $this->postedRun();
        $item = $run->items()->first();
        $this->service()->pay($item, $item->remaining_payable_ils, $this->bank->id);

        $recon = app(ReconciliationService::class)->salaryPayable();
        $this->assertTrue($recon['balanced']);
        $this->assertSame('0.00', $recon['gl_balance']); // fully paid
    }

    public function test_salary_payment_credits_selected_bank(): void
    {
        $run = $this->postedRun();
        $item = $run->items()->first();
        $payment = $this->service()->pay($item, '1000', $this->bank->id);

        $entry = JournalEntry::with('lines')->where('source_type', 'payroll_payment')->where('source_id', $payment->id)->firstOrFail();
        $cash = $entry->lines->firstWhere('financial_account_id', $this->bank->id);
        $this->assertSame('1000.00', $cash->credit_ils);
        $this->assertSame('1000.00', $entry->lines->firstWhere('account_id', Account::where('code', '2400')->value('id'))->debit_ils);
    }

    public function test_partial_salary_payment_supported(): void
    {
        $run = $this->postedRun('4000');
        $item = $run->items()->first();
        $this->service()->pay($item, '1500', $this->bank->id);

        $item->refresh();
        $this->assertSame('1500.00', $item->paid_amount_ils);
        $this->assertSame('2500.00', $item->remaining_payable_ils);
        $this->assertSame('posted', $run->fresh()->status->value); // not fully paid yet
    }

    public function test_fully_paid_employee_remaining_zero(): void
    {
        $run = $this->postedRun();
        $item = $run->items()->first();
        $this->service()->pay($item, $item->remaining_payable_ils, $this->bank->id);
        $this->assertSame('0.00', $item->fresh()->remaining_payable_ils);
    }

    public function test_run_becomes_paid_only_when_all_paid(): void
    {
        // Two employees are calculated in the run; the run stays "posted" until
        // BOTH are fully paid.
        $extra = $this->makeEmployee();
        $this->makeSalaryProfile($extra, '3000');
        $run = $this->postedRun();
        $this->assertGreaterThanOrEqual(2, $run->items()->count());

        $items = $run->items()->get();
        $this->service()->pay($items[0], $items[0]->remaining_payable_ils, $this->bank->id);
        $this->assertSame('posted', $run->fresh()->status->value); // still one unpaid

        foreach ($items->slice(1) as $item) {
            $this->service()->pay($item, $item->remaining_payable_ils, $this->bank->id);
        }
        $this->assertSame('paid', $run->fresh()->status->value);
    }

    public function test_salary_payment_cannot_exceed_remaining(): void
    {
        $run = $this->postedRun('4000');
        $item = $run->items()->first();
        $this->expectException(RuntimeException::class);
        $this->service()->pay($item, '999999', $this->bank->id);
    }

    public function test_posted_salary_payment_reversal_restores_payable(): void
    {
        $run = $this->postedRun();
        $item = $run->items()->first();
        $payment = $this->service()->pay($item, $item->remaining_payable_ils, $this->bank->id);
        $this->assertSame('paid', $run->fresh()->status->value);

        $this->service()->reverse($payment->fresh(), $this->makeUser(RoleName::GeneralManager), 'خطأ');

        $item->refresh();
        $this->assertSame('0.00', $item->paid_amount_ils);
        $this->assertTrue((float) $item->remaining_payable_ils > 0);
        $this->assertSame('posted', $run->fresh()->status->value); // back from paid
        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'salary_payment_reversal', 'source_id' => $payment->id]);
    }

    public function test_salary_payment_before_posting_is_rejected(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);

        $this->expectException(RuntimeException::class);
        $this->service()->pay($run->items()->first(), '100', $this->bank->id);
    }
}
