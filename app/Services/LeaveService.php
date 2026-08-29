<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestSubmitted;
use App\Notifications\LeaveStatusChanged;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Leave lifecycle: day counting (excludes non-working days & the weekly day
 * off), submission with overlap protection, and approve/reject/cancel flows
 * that keep the attendance ledger consistent. Approved leave is never
 * hard-deleted — cancellation is an auditable reversal.
 */
class LeaveService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditLogger $audit,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Count the working days in the inclusive range, skipping the weekly day
     * off and any non-working day defined in settings.
     */
    public function calculateDays(Carbon $start, Carbon $end): int
    {
        if ($end->lessThan($start)) {
            return 0;
        }

        $days = 0;
        foreach (CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()) as $date) {
            if ($this->attendance->isWorkingDay($date)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Whether the employee already has an approved leave overlapping the range.
     */
    public function hasOverlappingApproved(Employee $employee, Carbon $start, Carbon $end, ?int $ignoreId = null): bool
    {
        return LeaveRequest::where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
    }

    public function submit(
        Employee $employee,
        LeaveType $type,
        Carbon $start,
        Carbon $end,
        ?string $reason = null,
        ?string $attachmentPath = null,
    ): LeaveRequest {
        if ($end->lessThan($start)) {
            throw new RuntimeException('تاريخ النهاية يجب أن يكون بعد تاريخ البداية.');
        }

        if ($type->requires_attachment && ! $attachmentPath) {
            throw new RuntimeException('هذا النوع من الإجازات يتطلب إرفاق مستند.');
        }

        $days = $this->calculateDays($start, $end);
        if ($days < 1) {
            throw new RuntimeException('المدة المحددة لا تتضمن أي يوم عمل.');
        }

        if ($this->hasOverlappingApproved($employee, $start, $end)) {
            throw new RuntimeException('توجد إجازة معتمدة متداخلة مع هذه الفترة.');
        }

        return DB::transaction(function () use ($employee, $type, $start, $end, $days, $reason, $attachmentPath) {
            $request = LeaveRequest::create([
                'reference_no' => $this->numbers->next('leave'),
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_days' => $days,
                'reason' => $reason,
                'attachment_path' => $attachmentPath,
                'status' => LeaveStatus::Pending,
                'created_by' => Auth::id(),
            ]);

            $this->audit->log('leave_submitted', $request, 'Leaves', description: "تقديم طلب إجازة ({$days} يوم)");

            $approvers = User::permission('leaves.approve')->get();
            Notification::send($approvers, new LeaveRequestSubmitted($request));

            return $request;
        });
    }

    private function notifyEmployee(LeaveRequest $request): void
    {
        $user = $request->employee->user;
        if ($user) {
            $user->notify(new LeaveStatusChanged($request));
        }
    }

    public function approve(LeaveRequest $request, User $reviewer, ?string $notes = null): LeaveRequest
    {
        if (! $request->isPending()) {
            throw new RuntimeException('لا يمكن اعتماد طلب ليس قيد المراجعة.');
        }

        if ($this->hasOverlappingApproved($request->employee, Carbon::parse($request->start_date), Carbon::parse($request->end_date), $request->id)) {
            throw new RuntimeException('توجد إجازة معتمدة متداخلة مع هذه الفترة.');
        }

        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $request->update([
                'status' => LeaveStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            // Mark each working day within the range as Leave in attendance.
            foreach (CarbonPeriod::create(Carbon::parse($request->start_date), Carbon::parse($request->end_date)) as $date) {
                if ($this->attendance->isWorkingDay($date)) {
                    $this->attendance->markStatus($request->employee, Carbon::instance($date), AttendanceStatus::Leave, 'إجازة معتمدة');
                }
            }

            $this->audit->log('leave_approved', $request, 'Leaves', description: 'اعتماد إجازة');
            $this->notifyEmployee($request->fresh(['employee.user']));

            return $request;
        });
    }

    public function reject(LeaveRequest $request, User $reviewer, ?string $notes = null): LeaveRequest
    {
        if (! $request->isPending()) {
            throw new RuntimeException('لا يمكن رفض طلب ليس قيد المراجعة.');
        }

        $request->update([
            'status' => LeaveStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        // Rejection has no effect on attendance.
        $this->audit->log('leave_rejected', $request, 'Leaves', description: 'رفض إجازة');
        $this->notifyEmployee($request->fresh(['employee.user']));

        return $request;
    }

    /**
     * Employee self-cancel of a still-pending request.
     */
    public function cancelPending(LeaveRequest $request): LeaveRequest
    {
        if (! $request->isPending()) {
            throw new RuntimeException('لا يمكن إلغاء طلب تمت مراجعته.');
        }

        $request->update(['status' => LeaveStatus::Cancelled]);
        $this->audit->log('leave_cancelled', $request, 'Leaves', description: 'إلغاء طلب إجازة (قيد المراجعة)');

        return $request;
    }

    /**
     * Authorised reversal of an approved leave. Removes the system-synced Leave
     * attendance days (never real check-ins) and keeps the history in the audit
     * log rather than hard-deleting the request.
     */
    public function reverseApproved(LeaveRequest $request, ?string $notes = null): LeaveRequest
    {
        if (! $request->isApproved()) {
            throw new RuntimeException('يمكن فقط عكس إجازة معتمدة.');
        }

        return DB::transaction(function () use ($request, $notes) {
            AttendanceRecord::where('employee_id', $request->employee_id)
                ->whereBetween('attendance_date', [$request->start_date, $request->end_date])
                ->where('status', AttendanceStatus::Leave)
                ->whereNull('check_in_at')
                ->delete();

            $request->update([
                'status' => LeaveStatus::Cancelled,
                'review_notes' => trim(($request->review_notes ? $request->review_notes."\n" : '').'عكس/إلغاء: '.($notes ?? '')),
            ]);

            $this->audit->log('leave_reversed', $request, 'Leaves', description: 'عكس/إلغاء إجازة معتمدة');

            return $request;
        });
    }
}
