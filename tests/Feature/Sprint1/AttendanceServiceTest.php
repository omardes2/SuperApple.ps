<?php

namespace Tests\Feature\Sprint1;

use App\Enums\AttendanceStatus;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        // Deterministic clock on a working day (Wednesday 2026-09-02).
        Carbon::setTestNow(Carbon::create(2026, 9, 2, 9, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    public function test_grace_period_means_no_late_within_the_window(): void
    {
        // work_start 09:00 (default), grace 15 → threshold 09:15.
        $this->assertSame(0, $this->service()->lateMinutesFor(Carbon::create(2026, 9, 2, 9, 10)));
        $this->assertSame(0, $this->service()->lateMinutesFor(Carbon::create(2026, 9, 2, 9, 15)));
    }

    public function test_late_minutes_counted_after_grace_threshold(): void
    {
        // 09:22 → threshold 09:15 → 7 late minutes.
        $this->assertSame(7, $this->service()->lateMinutesFor(Carbon::create(2026, 9, 2, 9, 22)));
        $this->assertSame(30, $this->service()->lateMinutesFor(Carbon::create(2026, 9, 2, 9, 45)));
    }

    public function test_check_in_records_present_or_late_status(): void
    {
        [, $employee] = $this->makeStaff();

        Carbon::setTestNow(Carbon::create(2026, 9, 2, 9, 5));
        $record = $this->service()->checkIn($employee);

        $this->assertNotNull($record->check_in_at);
        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame(0, $record->late_minutes);
    }

    public function test_late_check_in_marks_status_late(): void
    {
        [, $employee] = $this->makeStaff();

        Carbon::setTestNow(Carbon::create(2026, 9, 2, 9, 40));
        $record = $this->service()->checkIn($employee);

        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertSame(25, $record->late_minutes);
    }

    public function test_employee_cannot_check_in_twice(): void
    {
        [, $employee] = $this->makeStaff();

        $this->service()->checkIn($employee);

        $this->expectException(RuntimeException::class);
        $this->service()->checkIn($employee);
    }

    public function test_cannot_check_out_before_check_in(): void
    {
        [, $employee] = $this->makeStaff();

        $this->expectException(RuntimeException::class);
        $this->service()->checkOut($employee);
    }

    public function test_worked_minutes_calculated_on_check_out(): void
    {
        [, $employee] = $this->makeStaff();

        Carbon::setTestNow(Carbon::create(2026, 9, 2, 9, 0));
        $this->service()->checkIn($employee);

        Carbon::setTestNow(Carbon::create(2026, 9, 2, 17, 30)); // 8.5h
        $record = $this->service()->checkOut($employee);

        $this->assertSame(510, $record->worked_minutes);
        // 8h contracted → 30 minutes overtime.
        $this->assertSame(30, $record->overtime_minutes);
    }

    public function test_cannot_check_out_twice(): void
    {
        [, $employee] = $this->makeStaff();

        $this->service()->checkIn($employee);
        Carbon::setTestNow(Carbon::create(2026, 9, 2, 17, 0));
        $this->service()->checkOut($employee);

        $this->expectException(RuntimeException::class);
        $this->service()->checkOut($employee);
    }

    public function test_timestamps_come_from_the_server_clock(): void
    {
        [, $employee] = $this->makeStaff();

        Carbon::setTestNow($fixed = Carbon::create(2026, 9, 2, 9, 3, 30));
        $record = $this->service()->checkIn($employee);

        $this->assertEquals($fixed->format('Y-m-d H:i'), $record->check_in_at->format('Y-m-d H:i'));
    }
}
