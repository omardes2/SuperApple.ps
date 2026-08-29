<?php

namespace Tests\Feature\Sprint1;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class LeaveServiceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function service(): LeaveService
    {
        return app(LeaveService::class);
    }

    private function leaveType(array $attrs = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'name' => 'إجازة سنوية',
            'code' => 'T'.str()->upper(str()->random(4)),
            'is_paid' => true,
            'requires_attachment' => false,
            'is_active' => true,
        ], $attrs));
    }

    public function test_weekly_day_off_and_non_working_days_are_excluded(): void
    {
        $friday = Carbon::now()->next(Carbon::FRIDAY);
        $saturday = $friday->copy()->addDay();

        // Fri + Sat are the weekend → zero working days.
        $this->assertSame(0, $this->service()->calculateDays($friday, $saturday));

        // Sunday..Thursday → 5 working days.
        $sunday = Carbon::now()->next(Carbon::SUNDAY);
        $thursday = $sunday->copy()->addDays(4);
        $this->assertSame(5, $this->service()->calculateDays($sunday, $thursday));
    }

    public function test_range_spanning_a_weekend_counts_only_working_days(): void
    {
        $wednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $nextTuesday = $wednesday->copy()->addDays(6); // Wed..Tue includes Fri+Sat

        // Wed, Thu, Sun, Mon, Tue = 5.
        $this->assertSame(5, $this->service()->calculateDays($wednesday, $nextTuesday));
    }

    public function test_employee_can_create_leave_request(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->actingAs($user);
        $sunday = Carbon::now()->next(Carbon::SUNDAY);

        $request = $this->service()->submit($employee, $this->leaveType(), $sunday, $sunday->copy()->addDay(), 'سبب');

        $this->assertSame(LeaveStatus::Pending, $request->status);
        $this->assertNotNull($request->reference_no);
        $this->assertSame(2, $request->total_days);
    }

    public function test_approved_leave_marks_attendance_as_leave(): void
    {
        [$user, $employee] = $this->makeStaff();
        $hr = $this->makeUser(RoleName::HrManager);
        $this->actingAs($user);

        $sunday = Carbon::now()->next(Carbon::SUNDAY);
        $monday = $sunday->copy()->addDay();
        $request = $this->service()->submit($employee, $this->leaveType(), $sunday, $monday);

        $this->service()->approve($request->fresh(), $hr);

        $this->assertSame(LeaveStatus::Approved, $request->fresh()->status);
        foreach ([$sunday, $monday] as $day) {
            $rec = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('attendance_date', $day->toDateString())->first();
            $this->assertNotNull($rec, "attendance for {$day->toDateString()} missing");
            $this->assertSame(AttendanceStatus::Leave, $rec->status);
        }
    }

    public function test_rejected_leave_does_not_affect_attendance(): void
    {
        [$user, $employee] = $this->makeStaff();
        $hr = $this->makeUser(RoleName::HrManager);
        $this->actingAs($user);

        $sunday = Carbon::now()->next(Carbon::SUNDAY);
        $request = $this->service()->submit($employee, $this->leaveType(), $sunday, $sunday->copy()->addDay());

        $this->service()->reject($request->fresh(), $hr, 'مرفوض');

        $this->assertSame(LeaveStatus::Rejected, $request->fresh()->status);
        $this->assertSame(0, AttendanceRecord::where('employee_id', $employee->id)->count());
    }

    public function test_overlapping_approved_leaves_are_rejected(): void
    {
        [$user, $employee] = $this->makeStaff();
        $hr = $this->makeUser(RoleName::HrManager);
        $this->actingAs($user);

        $sunday = Carbon::now()->next(Carbon::SUNDAY);
        $first = $this->service()->submit($employee, $this->leaveType(), $sunday, $sunday->copy()->addDays(3));
        $this->service()->approve($first->fresh(), $hr);

        $this->expectException(RuntimeException::class);
        // Overlaps the already-approved leave.
        $this->service()->submit($employee, $this->leaveType(), $sunday->copy()->addDay(), $sunday->copy()->addDays(2));
    }

    public function test_pending_leave_can_be_cancelled(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->actingAs($user);
        $sunday = Carbon::now()->next(Carbon::SUNDAY);

        $request = $this->service()->submit($employee, $this->leaveType(), $sunday, $sunday->copy()->addDay());
        $this->service()->cancelPending($request->fresh());

        $this->assertSame(LeaveStatus::Cancelled, $request->fresh()->status);
    }

    public function test_reversing_approved_leave_removes_synced_attendance(): void
    {
        [$user, $employee] = $this->makeStaff();
        $hr = $this->makeUser(RoleName::HrManager);
        $this->actingAs($user);

        $sunday = Carbon::now()->next(Carbon::SUNDAY);
        $request = $this->service()->submit($employee, $this->leaveType(), $sunday, $sunday->copy()->addDay());
        $this->service()->approve($request->fresh(), $hr);
        $this->assertSame(2, AttendanceRecord::where('status', AttendanceStatus::Leave)->count());

        $this->service()->reverseApproved($request->fresh(), 'إلغاء');

        $this->assertSame(LeaveStatus::Cancelled, $request->fresh()->status);
        $this->assertSame(0, AttendanceRecord::where('status', AttendanceStatus::Leave)->count());
    }

    public function test_attachment_required_when_leave_type_demands_it(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->actingAs($user);
        $sunday = Carbon::now()->next(Carbon::SUNDAY);

        $this->expectException(RuntimeException::class);
        $this->service()->submit($employee, $this->leaveType(['requires_attachment' => true]), $sunday, $sunday->copy()->addDay());
    }
}
