<?php

namespace Tests\Feature\Sprint1;

use App\Enums\RoleName;
use App\Livewire\Admin\LeavesIndex;
use App\Livewire\Employee\MyLeaves;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\PayrollService;
use Database\Seeders\LeaveTypesProductionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class LeaveTypeManagementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    // ---- Production seeder ----

    public function test_production_seeder_creates_the_core_types(): void
    {
        $this->seed(LeaveTypesProductionSeeder::class);

        $this->assertSame(4, LeaveType::count());
        $this->assertTrue(LeaveType::where('code', 'annual')->value('is_paid'));
        $this->assertTrue(LeaveType::where('code', 'sick')->value('is_paid'));
        $this->assertTrue(LeaveType::where('code', 'emergency')->value('is_paid'));
        $this->assertFalse((bool) LeaveType::where('code', 'unpaid')->value('is_paid'));
        foreach (['annual', 'sick', 'unpaid', 'emergency'] as $code) {
            $this->assertTrue((bool) LeaveType::where('code', $code)->value('is_active'));
        }
    }

    public function test_seeder_is_idempotent_no_duplicates(): void
    {
        $this->seed(LeaveTypesProductionSeeder::class);
        $this->seed(LeaveTypesProductionSeeder::class);
        $this->assertSame(4, LeaveType::count());
        $this->assertSame(1, LeaveType::where('code', 'annual')->count());
    }

    // ---- Employee visibility ----

    public function test_active_types_appear_to_employees_and_inactive_do_not(): void
    {
        [$user] = $this->makeStaff();
        $active = LeaveType::create(['name' => 'نشطة', 'code' => 'act', 'is_paid' => true, 'is_active' => true]);
        $inactive = LeaveType::create(['name' => 'معطّلة', 'code' => 'inact', 'is_paid' => true, 'is_active' => false]);

        Livewire::actingAs($user)->test(MyLeaves::class)
            ->assertViewHas('leaveTypes', function ($types) use ($active, $inactive) {
                $ids = $types->pluck('id');

                return $ids->contains($active->id) && ! $ids->contains($inactive->id);
            });
    }

    // ---- Permissions ----

    public function test_employee_cannot_open_leaves_admin_or_manage_types(): void
    {
        [$user] = $this->makeStaff();
        Livewire::actingAs($user)->test(LeavesIndex::class)->assertForbidden();
    }

    public function test_hr_can_manage_leave_types(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);
        Livewire::actingAs($hr)->test(LeavesIndex::class)
            ->call('openTypes')
            ->call('newType')
            ->set('typeName', 'إجازة أمومة')
            ->set('typeCode', 'maternity')
            ->set('typeIsPaid', true)
            ->call('saveType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_types', ['code' => 'maternity', 'is_paid' => true]);
    }

    public function test_super_admin_can_toggle_type_active(): void
    {
        $sa = $this->makeUser(RoleName::SuperAdmin);
        $type = LeaveType::create(['name' => 'x', 'code' => 'x1', 'is_paid' => true, 'is_active' => true]);

        Livewire::actingAs($sa)->test(LeavesIndex::class)->call('toggleTypeActive', $type->id);
        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);
        LeaveType::create(['name' => 'موجود', 'code' => 'dupe', 'is_paid' => true, 'is_active' => true]);

        Livewire::actingAs($hr)->test(LeavesIndex::class)
            ->call('newType')
            ->set('typeName', 'آخر')
            ->set('typeCode', 'dupe')
            ->call('saveType')
            ->assertHasErrors('typeCode');
    }

    // ---- Payroll behaviour with the seeded types ----

    private function fillWorkingDays($employee, $run, array $workDays): void
    {
        for ($d = $run->period_start->copy(); $d->lte($run->period_end); $d->addDay()) {
            if (in_array(self::DAYS[$d->dayOfWeek], $workDays, true)) {
                AttendanceRecord::create([
                    'employee_id' => $employee->id, 'attendance_date' => $d->toDateString(),
                    'status' => 'present', 'late_minutes' => 0, 'overtime_minutes' => 0, 'worked_minutes' => 480,
                ]);
            }
        }
    }

    private function approvedLeave($employee, LeaveType $type, string $date): void
    {
        LeaveRequest::create([
            'reference_no' => 'LV-'.$type->code.'-'.$date,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $date, 'end_date' => $date, 'total_days' => 1, 'status' => 'approved',
        ]);
    }

    public function test_paid_leave_remains_paid_in_payroll(): void
    {
        $this->seed(SettingsSeeder::class);
        $this->seed(LeaveTypesProductionSeeder::class);
        $workDays = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu'];

        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '3000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillWorkingDays($e, $run, $workDays);
        // Replace one working day (Monday 2026-08-03) with PAID annual leave.
        $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->delete();
        $this->approvedLeave($e, LeaveType::where('code', 'annual')->first(), '2026-08-03');

        app(PayrollService::class)->calculate($run);
        $item = $run->items()->where('employee_id', $e->id)->first();

        $this->assertSame(1.0, (float) $item->paid_leave_days);
        $this->assertSame(0, (int) $item->absent_days);
        $this->assertSame('0.00', $item->unpaid_leave_deduction_ils);
        $this->assertSame('3000.00', $item->net_salary_ils);
    }

    public function test_unpaid_leave_is_marked_and_deducts(): void
    {
        $this->seed(SettingsSeeder::class);
        $this->seed(LeaveTypesProductionSeeder::class);
        $workDays = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu'];

        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '3000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillWorkingDays($e, $run, $workDays);
        $e->attendanceRecords()->whereDate('attendance_date', '2026-08-03')->delete();
        $this->approvedLeave($e, LeaveType::where('code', 'unpaid')->first(), '2026-08-03');

        app(PayrollService::class)->calculate($run);
        $item = $run->items()->where('employee_id', $e->id)->first();

        // 3000 / 30 = 100 unpaid-leave deduction; not counted as absence.
        $this->assertSame('100.00', $item->unpaid_leave_deduction_ils);
        $this->assertSame(0, (int) $item->absent_days);
        $this->assertSame('2900.00', $item->net_salary_ils);
    }
}
