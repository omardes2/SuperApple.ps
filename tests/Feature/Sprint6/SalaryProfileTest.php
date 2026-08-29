<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SalaryProfileTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::HrManager));
    }

    public function test_salary_profile_can_be_created(): void
    {
        $employee = $this->makeEmployee();
        $profile = $this->makeSalaryProfile($employee, '4000', '2026-01-01');

        $this->assertSame('4000.00', $profile->base_salary_ils);
        $this->assertDatabaseHas('employee_salary_profiles', ['employee_id' => $employee->id, 'base_salary_ils' => '4000.00']);
    }

    public function test_effective_dated_salary_is_selected_correctly(): void
    {
        $employee = $this->makeEmployee();
        $this->makeSalaryProfile($employee, '3500', '2026-01-01');
        $this->makeSalaryProfile($employee, '4000', '2026-07-01');

        // August uses the July raise.
        $this->assertSame('4000.00', $employee->salaryProfileOn('2026-08-01')->base_salary_ils);
        // May still uses the old salary.
        $this->assertSame('3500.00', $employee->salaryProfileOn('2026-05-01')->base_salary_ils);
    }

    public function test_historical_salary_change_does_not_mutate_old_payroll(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $employee = $this->makeEmployee();
        $this->makeSalaryProfile($employee, '4000', '2026-01-01');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($employee, $run);
        app(PayrollService::class)->calculate($run);

        $itemBefore = $run->items()->where('employee_id', $employee->id)->first();

        // A later raise must not touch the already-calculated payroll item.
        $this->makeSalaryProfile($employee, '9000', '2026-09-01');

        $this->assertSame($itemBefore->base_salary_ils, $run->fresh()->items()->where('employee_id', $employee->id)->first()->base_salary_ils);
    }

    public function test_employee_cannot_view_others_salary(): void
    {
        [$user] = $this->makeStaff();
        $this->assertFalse($user->can('salary_profiles.view'));
    }

    public function test_employee_cannot_manage_salary(): void
    {
        [$user] = $this->makeStaff();
        $this->assertFalse($user->can('salary_profiles.manage'));
        $this->assertFalse($user->can('payroll.view'));
    }
}
