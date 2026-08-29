<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Enums\SystemAccountKey;
use App\Models\Account;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\SystemAccount;
use App\Services\AccountingReportService;
use App\Services\EmployeeAdvanceService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollAccountingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function postRun(Employee $e): PayrollRun
    {
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));

        return app(PayrollService::class)->post($run->fresh());
    }

    private function journal(int $runId): JournalEntry
    {
        return JournalEntry::with('lines')->where('source_type', 'payroll_run')
            ->where('source_id', $runId)->where('posting_type', 'payroll_run')->firstOrFail();
    }

    private function line(JournalEntry $entry, string $code): ?string
    {
        $l = $entry->lines->firstWhere('account_id', Account::where('code', $code)->value('id'));

        return $l ? ((float) $l->debit_ils > 0 ? $l->debit_ils : $l->credit_ils) : null;
    }

    public function test_basic_payroll_debits_salary_expense_credits_salary_payable(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $run = $this->postRun($e);

        $entry = $this->journal($run->id);
        $this->assertSame('4000.00', $this->line($entry, '5200')); // salary expense
        $this->assertSame('4000.00', $this->line($entry, '2400')); // salary payable
        $this->assertSame($entry->totalDebit(), $entry->totalCredit());
    }

    public function test_advance_recovery_credits_employee_advances(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $bank = $this->makeCashAccount('ILS', '10000');
        $adv = app(EmployeeAdvanceService::class)->create(['employee_id' => $e->id, 'amount_ils' => '1000', 'installment_ils' => '500', 'financial_account_id' => $bank->id]);
        app(EmployeeAdvanceService::class)->approve($adv->fresh(), $this->makeUser(RoleName::HrManager));
        app(EmployeeAdvanceService::class)->pay($adv->fresh());

        $run = $this->postRun($e);
        $entry = $this->journal($run->id);
        // Dr Salary Expense 4,000 ; Cr Employee Advances 500 ; Cr Salary Payable 3,500.
        $this->assertSame('4000.00', $this->line($entry, '5200'));
        $this->assertSame('500.00', $this->line($entry, '1400'));
        $this->assertSame('3500.00', $this->line($entry, '2400'));
        $this->assertSame('500.00', $adv->fresh()->remaining_ils);
    }

    public function test_payroll_journal_is_balanced(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '5000');
        $run = $this->postRun($e);
        $entry = $this->journal($run->id);
        $this->assertSame($entry->totalDebit(), $entry->totalCredit());
    }

    public function test_salary_expense_appears_once_in_pl(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $this->postRun($e);

        $pl = app(AccountingReportService::class)->profitAndLoss();
        $salaryLines = collect($pl['expenses'])->filter(fn ($r) => $r['account']->code === '5200');
        $this->assertCount(1, $salaryLines);
        $this->assertSame('4000.00', $salaryLines->first()['amount']);
    }

    public function test_posting_failure_rolls_back_payroll_status(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        // Break the salary-payable system account so GL posting fails.
        SystemAccount::where('key', SystemAccountKey::SalaryPayable->value)->delete();

        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));

        try {
            app(PayrollService::class)->post($run->fresh());
            $this->fail('Expected posting to fail.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('approved', $run->fresh()->status->value);
        $this->assertSame(0, JournalEntry::where('source_type', 'payroll_run')->count());
    }

    public function test_reversal_journal_reverses_payroll(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $run = $this->postRun($e);

        app(PayrollService::class)->reverse($run->fresh(), $this->makeUser(RoleName::GeneralManager), 'خطأ');

        $original = $this->journal($run->id);
        $this->assertSame('reversed', $original->fresh()->status->value);
        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'payroll_run_reversal', 'source_id' => $run->id]);
        $this->assertSame('cancelled', $run->fresh()->status->value);
    }
}
