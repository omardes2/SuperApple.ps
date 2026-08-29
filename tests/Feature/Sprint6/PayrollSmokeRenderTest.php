<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Services\EmployeeAdvanceService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollSmokeRenderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_payroll_admin_pages_render(): void
    {
        $employee = $this->makeEmployee();
        $this->makeSalaryProfile($employee, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($employee, $run);
        app(PayrollService::class)->calculate($run);

        foreach (['/admin/payroll', '/admin/advances', '/admin/payroll/reports'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('admin.payroll.show', $run))->assertOk()->assertSee($run->payroll_number);
        $this->get(route('admin.employees.payroll', $employee))->assertOk();
    }

    public function test_payslip_and_my_payslips_render(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $this->makeSalaryProfile($employee, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($employee, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));
        app(PayrollService::class)->post($run->fresh());
        $item = $run->items()->where('employee_id', $employee->id)->first();

        $this->actingAs($user);
        $this->get('/employee/payslips')->assertOk();
        $this->get(route('payslips.print', $item))->assertOk()->assertSee($employee->full_name);
    }

    public function test_advance_detail_flow_renders(): void
    {
        $bank = $this->makeCashAccount('ILS', '10000');
        $e = $this->makeEmployee();
        app(EmployeeAdvanceService::class)->create(['employee_id' => $e->id, 'amount_ils' => '500', 'financial_account_id' => $bank->id]);

        $this->get('/admin/advances')->assertOk()->assertSee('سلف الموظفين');
    }
}
