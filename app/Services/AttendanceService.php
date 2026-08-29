<?php

namespace App\Services;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Notifications\AttendanceAdjusted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * All attendance business logic: check-in/out, late/worked/overtime maths, and
 * administrative adjustments. Timestamps always come from the server clock —
 * never the client — so employees cannot backdate their attendance.
 */
class AttendanceService
{
    /** Short day keys indexed by Carbon dayOfWeek (0 = Sunday .. 6 = Saturday). */
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function workStart(): string
    {
        return (string) $this->settings->get('attendance', 'work_start', '09:00');
    }

    public function workEnd(): string
    {
        return (string) $this->settings->get('attendance', 'work_end', '17:00');
    }

    public function graceMinutes(): int
    {
        return (int) $this->settings->get('attendance', 'grace_minutes', 15);
    }

    /** @return list<string> */
    public function workingDays(): array
    {
        return (array) $this->settings->get('attendance', 'work_days', ['sun', 'mon', 'tue', 'wed', 'thu']);
    }

    public function isWorkingDay(Carbon|CarbonImmutable $date): bool
    {
        return in_array(self::DAYS[$date->dayOfWeek], $this->workingDays(), true);
    }

    /**
     * Minutes past the grace threshold (work_start + grace). The first grace
     * minutes are never counted. Example: start 08:00, grace 15, check-in 08:22
     * → threshold 08:15 → 7 late minutes.
     */
    public function lateMinutesFor(Carbon $checkInAt): int
    {
        [$h, $m] = array_map('intval', explode(':', $this->workStart()));
        $threshold = $checkInAt->copy()->setTime($h, $m)->addMinutes($this->graceMinutes());

        if ($checkInAt->lessThanOrEqualTo($threshold)) {
            return 0;
        }

        return $threshold->diffInMinutes($checkInAt);
    }

    /**
     * Register a check-in. Fails if the employee already checked in today.
     */
    public function checkIn(Employee $employee, ?Carbon $now = null): AttendanceRecord
    {
        $now = $now ?: now();
        $date = $now->copy()->startOfDay();

        return DB::transaction(function () use ($employee, $now, $date) {
            $existing = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->check_in_at) {
                throw new RuntimeException('تم تسجيل حضورك مسبقاً لهذا اليوم.');
            }

            $late = $this->lateMinutesFor($now);

            $record = $existing ?: new AttendanceRecord([
                'employee_id' => $employee->id,
                'attendance_date' => $date->toDateString(),
            ]);

            $record->fill([
                'check_in_at' => $now,
                'late_minutes' => $late,
                'status' => $late > 0 ? AttendanceStatus::Late : AttendanceStatus::Present,
                'check_in_source' => AttendanceSource::SelfService->value,
                'created_by' => Auth::id(),
            ]);
            $record->save();

            return $record;
        });
    }

    /**
     * Register a check-out. Requires an existing check-in and no prior checkout.
     */
    public function checkOut(Employee $employee, ?Carbon $now = null): AttendanceRecord
    {
        $now = $now ?: now();
        $date = $now->copy()->startOfDay();

        return DB::transaction(function () use ($employee, $now, $date) {
            $record = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            if (! $record || ! $record->check_in_at) {
                throw new RuntimeException('لا يمكن تسجيل الانصراف قبل تسجيل الحضور.');
            }

            if ($record->check_out_at) {
                throw new RuntimeException('تم تسجيل انصرافك مسبقاً لهذا اليوم.');
            }

            $worked = $record->check_in_at->diffInMinutes($now);

            $record->fill([
                'check_out_at' => $now,
                'worked_minutes' => $worked,
                'overtime_minutes' => $this->overtimeMinutes($employee, $worked),
                'check_out_source' => AttendanceSource::SelfService->value,
                'updated_by' => Auth::id(),
            ]);
            $record->save();

            return $record;
        });
    }

    /**
     * Standard-day overtime: worked minutes beyond the employee's contracted
     * daily hours (falls back to the company default).
     */
    public function overtimeMinutes(Employee $employee, int $workedMinutes): int
    {
        $standard = (float) ($employee->working_hours_per_day
            ?: $this->settings->get('attendance', 'default_working_hours', 8));

        return max(0, $workedMinutes - (int) round($standard * 60));
    }

    /**
     * Administrative adjustment of a record by an authorised user. Recomputes
     * derived fields and writes an explicit audit entry with before/after.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function adjust(AttendanceRecord $record, array $attributes): AttendanceRecord
    {
        return DB::transaction(function () use ($record, $attributes) {
            $before = $record->only(['check_in_at', 'check_out_at', 'status', 'worked_minutes', 'late_minutes', 'overtime_minutes', 'notes']);

            $record->fill($attributes);

            if ($record->check_in_at) {
                $record->late_minutes = $this->lateMinutesFor(Carbon::parse($record->check_in_at));
            }
            if ($record->check_in_at && $record->check_out_at) {
                $worked = Carbon::parse($record->check_in_at)->diffInMinutes(Carbon::parse($record->check_out_at));
                $record->worked_minutes = $worked;
                $record->overtime_minutes = $this->overtimeMinutes($record->employee, $worked);
            }

            $record->check_in_source = $record->check_in_source ?: AttendanceSource::Adjustment->value;
            $record->check_out_source = $record->check_out_at ? AttendanceSource::Adjustment->value : $record->check_out_source;
            $record->approved_by = Auth::id();
            $record->approved_at = now();
            $record->updated_by = Auth::id();
            $record->save();

            $this->audit->log(
                action: 'attendance_adjusted',
                subject: $record,
                module: 'Attendance',
                old: $before,
                new: $record->only(array_keys($before)),
                description: 'تعديل سجل دوام',
            );

            $user = $record->employee->user;
            if ($user) {
                $user->notify(new AttendanceAdjusted($record));
            }

            return $record;
        });
    }

    /**
     * Ensure a record exists for an employee/day carrying the given status
     * (used by LeaveService to mark approved leave days). Never overwrites an
     * actual check-in.
     */
    public function markStatus(Employee $employee, Carbon $date, AttendanceStatus $status, ?string $note = null): void
    {
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first()
            ?? new AttendanceRecord([
                'employee_id' => $employee->id,
                'attendance_date' => $date->toDateString(),
            ]);

        if ($record->check_in_at) {
            return; // real attendance takes precedence over a synced status
        }

        $record->status = $status;
        $record->check_in_source = AttendanceSource::System->value;
        $record->notes = $note ?: $record->notes;
        $record->save();
    }

    /**
     * Monthly totals for one employee.
     *
     * @return array{present:int,late:int,absent:int,leave:int,worked_minutes:int,late_minutes:int,overtime_minutes:int}
     */
    public function monthlySummary(Employee $employee, int $year, int $month): array
    {
        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereYear('attendance_date', $year)
            ->whereMonth('attendance_date', $month)
            ->get();

        return [
            'present' => $records->where('status', AttendanceStatus::Present)->count(),
            'late' => $records->where('status', AttendanceStatus::Late)->count(),
            'absent' => $records->where('status', AttendanceStatus::Absent)->count(),
            'leave' => $records->where('status', AttendanceStatus::Leave)->count(),
            'worked_minutes' => (int) $records->sum('worked_minutes'),
            'late_minutes' => (int) $records->sum('late_minutes'),
            'overtime_minutes' => (int) $records->sum('overtime_minutes'),
        ];
    }
}
