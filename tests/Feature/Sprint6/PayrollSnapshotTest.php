<?php

namespace Tests\Feature\Sprint6;

use App\Enums\RoleName;
use App\Models\Department;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollSnapshotTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function approvedItem(&$employee, &$run)
    {
        $dept = Department::create(['name' => 'التصميم', 'code' => 'DSGN', 'is_active' => true]);
        $employee = $this->makeEmployee(null, ['full_name' => 'سامي', 'job_title' => 'مصمم', 'department_id' => $dept->id]);
        $this->makeSalaryProfile($employee, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillFullAttendance($employee, $run);
        app(PayrollService::class)->calculate($run);
        app(PayrollService::class)->approve($run->fresh(), $this->makeUser(RoleName::GeneralManager));

        return $run->items()->where('employee_id', $employee->id)->first();
    }

    public function test_payroll_stores_employee_name_snapshot(): void
    {
        $item = $this->approvedItem($e, $run);
        $this->assertSame('سامي', $item->employee_name_snapshot);
    }

    public function test_department_snapshot_preserved(): void
    {
        $item = $this->approvedItem($e, $run);
        $this->assertSame('التصميم', $item->department_snapshot);
        $this->assertSame('مصمم', $item->job_title_snapshot);
    }

    public function test_salary_snapshot_preserved(): void
    {
        $item = $this->approvedItem($e, $run);
        $this->assertSame('4000.00', $item->base_salary_ils);
    }

    public function test_attendance_change_after_approval_does_not_alter_payroll(): void
    {
        $item = $this->approvedItem($e, $run);
        $before = $item->net_salary_ils;

        // Delete attendance (would have caused absence) — but payroll is approved.
        $e->attendanceRecords()->delete();

        $this->assertSame($before, $run->fresh()->items()->where('employee_id', $e->id)->first()->net_salary_ils);
    }

    public function test_salary_profile_change_after_approval_does_not_alter_payroll(): void
    {
        $item = $this->approvedItem($e, $run);
        $before = $item->base_salary_ils;

        $this->makeSalaryProfile($e, '9999', '2026-08-01');

        $this->assertSame($before, $run->fresh()->items()->where('employee_id', $e->id)->first()->base_salary_ils);
    }
}
