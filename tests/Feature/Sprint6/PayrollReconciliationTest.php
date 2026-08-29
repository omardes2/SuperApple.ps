<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Models\JournalEntry;
use App\Services\AccountingReportService;
use App\Services\EmployeeAdvanceService;
use App\Services\PayrollPaymentService;
use App\Services\PayrollService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollReconciliationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $this->buildScenario();
    }

    private function buildScenario(): void
    {
        $bank = $this->makeCashAccount('ILS', '80000');
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');

        // Advance paid + partially recovered.
        $adv = app(EmployeeAdvanceService::class)->create(['employee_id' => $e->id, 'amount_ils' => '1000', 'installment_ils' => '400', 'financial_account_id' => $bank->id]);
        app(EmployeeAdvanceService::class)->approve($adv->fresh(), $this->makeUser(RoleName::HrManager));
        app(EmployeeAdvanceService::class)->pay($adv->fresh());

        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());

        // Pay one item partially.
        $item = $run->items()->first();
        app(PayrollPaymentService::class)->pay($item, '1000', $bank->id);
    }

    public function test_salary_payable_reconciliation_passes(): void
    {
        $this->assertTrue(app(ReconciliationService::class)->salaryPayable()['balanced']);
    }

    public function test_employee_advances_reconciliation_passes(): void
    {
        $this->assertTrue(app(ReconciliationService::class)->employeeAdvances()['balanced']);
    }

    public function test_trial_balance_remains_balanced(): void
    {
        $tb = app(AccountingReportService::class)->trialBalance();
        $this->assertSame($tb['totals']['ending_debit'], $tb['totals']['ending_credit']);
    }

    public function test_balance_sheet_remains_balanced(): void
    {
        $this->assertTrue(app(AccountingReportService::class)->balanceSheet()['balanced']);
    }

    public function test_payroll_journal_appears_in_gl(): void
    {
        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'payroll_run']);
        $this->assertGreaterThan(0, JournalEntry::where('posting_type', 'salary_payment')->count());
    }
}
