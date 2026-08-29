<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Services\EmployeeAdvanceService;
use App\Services\PayrollService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollCalculationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function calcFor(Employee $employee, ?callable $before = null, int $month = 8): PayrollItem
    {
        $run = $this->makePayrollRun(2026, $month);
        $this->fillFullAttendance($employee, $run);
        if ($before) {
            $before($run);
        }
        app(PayrollService::class)->calculate($run);

        return $run->items()->where('employee_id', $employee->id)->first();
    }

    public function test_payroll_run_is_unique_per_month(): void
    {
        $this->makePayrollRun(2026, 8);
        $this->expectException(RuntimeException::class);
        $this->makePayrollRun(2026, 8);
    }

    public function test_monthly_salary_calculated_correctly(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $item = $this->calcFor($e);
        // Full attendance, no adjustments → net == base.
        $this->assertSame('4000.00', $item->gross_salary_ils);
        $this->assertSame('4000.00', $item->net_salary_ils);
    }

    public function test_paid_leave_does_not_deduct(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $item = $this->calcFor($e, function ($run) use ($e) {
            // Replace one attended day with paid leave.
            $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->delete();
            $this->makeApprovedLeave($e, true, '2026-08-03', '2026-08-03');
        });
        $this->assertSame('0.00', $item->unpaid_leave_deduction_ils);
        $this->assertSame('4000.00', $item->net_salary_ils);
        $this->assertSame(1.0, (float) $item->paid_leave_days);
    }

    public function test_unpaid_leave_deducts(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '3000');
        $item = $this->calcFor($e, function ($run) use ($e) {
            $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->delete();
            $this->makeApprovedLeave($e, false, '2026-08-03', '2026-08-03');
        });
        // 3000 / 30 = 100 unpaid-leave deduction.
        $this->assertSame('100.00', $item->unpaid_leave_deduction_ils);
        $this->assertSame('2900.00', $item->net_salary_ils);
    }

    public function test_absence_deduction_is_correct(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '3000');
        $item = $this->calcFor($e, fn ($run) => $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->delete());
        // 1 absent day → 3000/30 = 100.
        $this->assertSame('100.00', $item->absence_deduction_ils);
        $this->assertSame(1.0, (float) $item->absent_days);
    }

    public function test_grace_is_not_deducted_twice(): void
    {
        // Payroll uses attendance late_minutes as-is (grace already applied upstream).
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '3000');
        app(Settings::class)->set('payroll', 'late_deduction_enabled', true, 'bool');
        $item = $this->calcFor($e, function ($run) use ($e) {
            $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->update(['late_minutes' => 30]);
        });
        $this->assertSame(30, (int) $item->late_minutes);
        // minute rate = 3000/30/8/60; 30 min → deduction > 0 (grace not re-applied).
        $this->assertTrue((float) $item->late_deduction_ils > 0);
    }

    public function test_late_deduction_disabled_by_default(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '3000');
        $item = $this->calcFor($e, fn ($run) => $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->update(['late_minutes' => 60]));
        // late deduction off by default → 0 even with late minutes.
        $this->assertSame('0.00', $item->late_deduction_ils);
    }

    public function test_overtime_calculated_correctly(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000', '2026-01-01', '30'); // 30 ILS/hour overtime
        $item = $this->calcFor($e, fn ($run) => $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->update(['overtime_minutes' => 120]));
        // 2 hours × 30 = 60.
        $this->assertSame('60.00', $item->overtime_amount_ils);
        $this->assertSame('4060.00', $item->gross_salary_ils);
    }

    public function test_bonus_added(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $item = $this->calcFor($e, fn ($run) => $e->salaryAdjustments()->create([
            'adjustment_type' => 'earning', 'category' => 'bonus', 'amount_ils' => '500',
            'effective_date' => '2026-08-15', 'status' => 'active',
        ]));
        $this->assertSame('500.00', $item->bonuses_ils);
        $this->assertSame('4500.00', $item->net_salary_ils);
    }

    public function test_allowance_added(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $item = $this->calcFor($e, fn ($run) => $e->salaryAdjustments()->create([
            'adjustment_type' => 'earning', 'category' => 'allowance', 'amount_ils' => '300',
            'effective_date' => '2026-08-01', 'is_recurring' => true, 'status' => 'active',
        ]));
        $this->assertSame('300.00', $item->allowances_ils);
    }

    public function test_commission_added(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $item = $this->calcFor($e, fn ($run) => $e->salaryAdjustments()->create([
            'adjustment_type' => 'earning', 'category' => 'commission', 'amount_ils' => '250',
            'effective_date' => '2026-08-10', 'status' => 'active',
        ]));
        $this->assertSame('250.00', $item->commissions_ils);
    }

    public function test_manual_deduction_applied(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $item = $this->calcFor($e, fn ($run) => $e->salaryAdjustments()->create([
            'adjustment_type' => 'deduction', 'category' => 'penalty', 'amount_ils' => '200',
            'effective_date' => '2026-08-10', 'status' => 'active',
        ]));
        $this->assertSame('200.00', $item->other_deductions_ils);
        $this->assertSame('3800.00', $item->net_salary_ils);
    }

    public function test_net_salary_calculation_correct(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $item = $this->calcFor($e, function ($run) use ($e) {
            $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->delete(); // 1 absent → -133.33
            $e->salaryAdjustments()->create(['adjustment_type' => 'earning', 'category' => 'bonus', 'amount_ils' => '200', 'effective_date' => '2026-08-10', 'status' => 'active']);
        });
        // 4000 + 200 bonus − 133.33 absence = 4066.67.
        $this->assertSame('4066.67', $item->net_salary_ils);
    }

    public function test_net_salary_cannot_go_negative(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '3000');
        $item = $this->calcFor($e, fn ($run) => $e->salaryAdjustments()->create([
            'adjustment_type' => 'deduction', 'category' => 'penalty', 'amount_ils' => '99999',
            'effective_date' => '2026-08-10', 'status' => 'active',
        ]));
        $this->assertSame('0.00', $item->net_salary_ils);
        $this->assertTrue((float) $item->net_salary_ils >= 0);
    }

    public function test_excess_advance_carries_forward(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '1000');
        // Advance 5000, no installment → tries to recover all, but net only allows 1000.
        $bank = $this->makeCashAccount('ILS', '10000');
        $adv = app(EmployeeAdvanceService::class)->create(['employee_id' => $e->id, 'amount_ils' => '5000', 'financial_account_id' => $bank->id]);
        app(EmployeeAdvanceService::class)->approve($adv->fresh(), $this->makeUser(RoleName::HrManager));
        app(EmployeeAdvanceService::class)->pay($adv->fresh());

        $item = $this->calcFor($e);
        // Recovery capped at 1000 (the whole net); net becomes 0.
        $this->assertSame('1000.00', $item->advances_deduction_ils);
        $this->assertSame('0.00', $item->net_salary_ils);
    }
}
