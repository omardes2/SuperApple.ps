<?php

namespace Tests\Feature\Sprint1;

use App\Enums\RoleName;
use App\Livewire\Admin\EmployeesIndex;
use App\Livewire\Employee\Dashboard as EmployeeDashboard;
use App\Livewire\Employee\MyAttendance;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class HrAuthorizationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_employee_cannot_access_employee_management(): void
    {
        [$user] = $this->makeStaff();

        // No admin experience → bounced out of the back-office entirely.
        $this->actingAs($user)->get('/admin/employees')->assertRedirect(route('employee.dashboard'));
    }

    public function test_employee_management_component_is_forbidden_for_employee(): void
    {
        [$user] = $this->makeStaff();

        Livewire::actingAs($user)->test(EmployeesIndex::class)->assertForbidden();
    }

    public function test_hr_can_access_employee_management(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);

        $this->actingAs($hr)->get('/admin/employees')->assertOk();
    }

    public function test_hr_can_access_departments_attendance_and_leaves(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);

        $this->actingAs($hr)->get('/admin/departments')->assertOk();
        $this->actingAs($hr)->get('/admin/attendance')->assertOk();
        $this->actingAs($hr)->get('/admin/leaves')->assertOk();
    }

    public function test_employee_can_view_own_attendance_page(): void
    {
        [$user] = $this->makeStaff();

        $this->actingAs($user)->get('/employee/attendance')->assertOk();
    }

    public function test_employee_only_sees_their_own_attendance_records(): void
    {
        [$user, $employee] = $this->makeStaff();
        [, $other] = $this->makeStaff();

        Carbon::setTestNow(Carbon::create(2026, 9, 2, 9, 0));
        $service = app(AttendanceService::class);
        $service->checkIn($employee);
        $service->checkIn($other);

        // Keep the clock on the same month the records belong to while the
        // component renders its monthly history.
        Livewire::actingAs($user)->test(MyAttendance::class)
            ->assertViewHas('records', fn ($records) => $records->count() === 1
                && $records->every(fn ($r) => $r->employee_id === $employee->id));

        Carbon::setTestNow();
    }

    public function test_employee_lacks_view_all_attendance_permission(): void
    {
        [$user] = $this->makeStaff();

        $this->assertTrue($user->can('attendance.view_own'));
        $this->assertFalse($user->can('attendance.view'));
    }

    public function test_financial_routes_remain_inaccessible_to_employee(): void
    {
        [$user] = $this->makeStaff();

        // Admin settings/audit are outside the employee experience.
        $this->actingAs($user)->get('/admin/settings')->assertRedirect(route('employee.dashboard'));
        $this->assertFalse($user->can('invoices.view'));
        $this->assertFalse($user->can('payroll.view'));
    }

    public function test_employee_record_carries_no_financial_fields(): void
    {
        [, $employee] = $this->makeStaff();

        foreach (['salary', 'base_salary', 'commission', 'bonuses', 'deductions', 'financial_cost', 'net_salary'] as $field) {
            $this->assertFalse(Schema::hasColumn('employees', $field), "employees must not have a [{$field}] column");
            $this->assertArrayNotHasKey($field, $employee->toArray());
        }
    }

    public function test_employee_dashboard_has_no_financial_content(): void
    {
        [$user] = $this->makeStaff();

        Livewire::actingAs($user)->test(EmployeeDashboard::class)
            ->assertOk()
            ->assertDontSee('الراتب')
            ->assertDontSee('الفواتير')
            ->assertDontSee('الأرباح');
    }
}
