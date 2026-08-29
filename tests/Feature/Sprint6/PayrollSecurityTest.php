<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Livewire\Admin\EmployeeAdvancesIndex;
use App\Livewire\Admin\PayrollIndex;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollSecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function payrollItemFor(User $forUser): PayrollItem
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $employee = $this->makeEmployee($forUser);
        $this->makeSalaryProfile($employee, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($employee, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());
        auth()->logout();

        return $run->items()->where('employee_id', $employee->id)->first();
    }

    public function test_employee_cannot_access_payroll_admin(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAs($user)->get('/admin/payroll')->assertRedirect(route('employee.dashboard'));
        $this->actingAs($user)->get('/admin/advances')->assertRedirect(route('employee.dashboard'));
    }

    public function test_employee_cannot_open_another_payslip(): void
    {
        [$otherUser] = $this->makeStaff();
        $item = $this->payrollItemFor($otherUser); // belongs to someone else

        [$viewer] = $this->makeStaff();
        $this->assertFalse($viewer->can('view', $item));
        $this->actingAs($viewer)->get(route('payslips.print', $item))->assertForbidden();
    }

    public function test_employee_can_view_own_payslip(): void
    {
        [$user, $employee] = $this->makeStaff();
        $item = $this->payrollItemFor($user);

        $this->assertTrue($user->can('view', $item));
        $this->actingAs($user)->get(route('payslips.print', $item))->assertOk();
    }

    public function test_hr_cannot_access_gl_automatically(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);
        // HR prepares payroll but has no GL / posting.
        $this->assertTrue($hr->can('payroll.calculate'));
        $this->assertFalse($hr->can('journals.view'));
        $this->assertFalse($hr->can('accounting.view'));
        $this->assertFalse($hr->can('payroll.post'));
    }

    public function test_accountant_has_no_hr_salary_management(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        // Accountant posts/pays payroll but cannot manage salary profiles.
        $this->assertTrue($accountant->can('payroll.post'));
        $this->assertTrue($accountant->can('payroll.pay'));
        $this->assertFalse($accountant->can('salary_profiles.manage'));
        $this->assertFalse($accountant->can('employees.manage'));
    }

    public function test_pm_cannot_access_payroll(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        foreach (['payroll.view', 'salary_profiles.view', 'advances.view', 'payroll.post'] as $perm) {
            $this->assertFalse($pm->can($perm), "PM must not have [{$perm}]");
        }
        Livewire::actingAs($pm)->test(PayrollIndex::class)->assertForbidden();
        Livewire::actingAs($pm)->test(EmployeeAdvancesIndex::class)->assertForbidden();
    }

    public function test_employee_has_no_salary_fields_exposure(): void
    {
        [$user] = $this->makeStaff();
        $this->assertFalse($user->can('salary_profiles.view'));
        $this->assertFalse($user->can('payslips.view_all'));
        // But may see their own payslip.
        $this->assertTrue($user->can('payslips.view_own'));
    }
}
