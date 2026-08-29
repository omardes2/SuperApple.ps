<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\EmployeeAdvance;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Services\EmployeeAdvanceService;
use App\Services\PayrollService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AdvanceTest extends TestCase
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

    private function service(): EmployeeAdvanceService
    {
        return app(EmployeeAdvanceService::class);
    }

    private function paidAdvance(string $amount = '1000', ?string $installment = null): EmployeeAdvance
    {
        $e = $this->makeEmployee();
        $adv = $this->service()->create(['employee_id' => $e->id, 'amount_ils' => $amount, 'installment_ils' => $installment, 'financial_account_id' => $this->bank->id]);
        $this->service()->approve($adv->fresh(), $this->makeUser(RoleName::HrManager));

        return $this->service()->pay($adv->fresh());
    }

    public function test_advance_number_is_generated(): void
    {
        $e = $this->makeEmployee();
        $adv = $this->service()->create(['employee_id' => $e->id, 'amount_ils' => '500', 'financial_account_id' => $this->bank->id]);
        $this->assertStringStartsWith('ADV-', $adv->advance_number);
    }

    public function test_approved_advance_can_be_paid(): void
    {
        $adv = $this->paidAdvance();
        $this->assertSame('paid', $adv->status->value);
    }

    public function test_advance_payment_debits_advances_credits_cash(): void
    {
        $adv = $this->paidAdvance('1000');
        $entry = JournalEntry::with('lines')->where('source_type', 'employee_advance')->where('source_id', $adv->id)->firstOrFail();
        $this->assertSame('1000.00', $entry->lines->firstWhere('account_id', Account::where('code', '1400')->value('id'))->debit_ils);
        $this->assertSame('1000.00', $entry->lines->firstWhere('financial_account_id', $this->bank->id)->credit_ils);
    }

    public function test_advance_deduction_reduces_outstanding(): void
    {
        // Full payroll cycle recovers the advance.
        $adv = $this->paidAdvance('1000', '400');
        $e = $adv->employee;
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());

        $this->assertSame('600.00', $adv->fresh()->remaining_ils); // 1000 − 400
        $this->assertSame('partially_recovered', $adv->fresh()->status->value);
    }

    public function test_advance_recovery_posts_correct_gl_line(): void
    {
        $adv = $this->paidAdvance('1000', '400');
        $e = $adv->employee;
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());

        $entry = JournalEntry::with('lines')->where('source_type', 'payroll_run')->where('source_id', $run->id)->firstOrFail();
        $this->assertSame('400.00', $entry->lines->firstWhere('account_id', Account::where('code', '1400')->value('id'))->credit_ils);
    }

    public function test_advance_reconciliation_passes(): void
    {
        $this->paidAdvance('1000');
        $this->assertTrue(app(ReconciliationService::class)->employeeAdvances()['balanced']);
    }

    public function test_advance_remaining_cannot_go_negative(): void
    {
        // Recover more than remaining across a large salary → capped at remaining.
        $adv = $this->paidAdvance('500'); // no installment → recover all
        $e = $adv->employee;
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());

        $this->assertSame('0.00', $adv->fresh()->remaining_ils);
        $this->assertSame('recovered', $adv->fresh()->status->value);
    }

    public function test_paid_advance_is_not_hard_deleted_on_cancel(): void
    {
        $adv = $this->paidAdvance('1000');
        $this->service()->cancel($adv->fresh(), $this->makeUser(RoleName::GeneralManager), 'خطأ');

        $this->assertDatabaseHas('employee_advances', ['id' => $adv->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'advance_payment_reversal', 'source_id' => $adv->id]);
    }

    public function test_recovered_advance_cannot_be_cancelled(): void
    {
        $adv = $this->paidAdvance('500');
        $e = $adv->employee;
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($e, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());

        $this->expectException(RuntimeException::class);
        $this->service()->cancel($adv->fresh(), $this->makeUser(RoleName::GeneralManager), 'محاولة');
    }
}
