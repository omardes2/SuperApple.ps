<?php

namespace App\Livewire\Employee;

use App\Enums\LeaveStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ResolvesActingEmployee;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.employee')]
#[Title('الرئيسية')]
class Dashboard extends Component
{
    use ResolvesActingEmployee;

    public function checkIn(AttendanceService $service): void
    {
        $this->authorize('attendance.check_in');

        try {
            $service->checkIn($this->actingEmployee());
            session()->flash('status', 'تم تسجيل حضورك.');
        } catch (\RuntimeException $e) {
            $this->addError('attendance', $e->getMessage());
        }
    }

    public function checkOut(AttendanceService $service): void
    {
        $this->authorize('attendance.check_out');

        try {
            $service->checkOut($this->actingEmployee());
            session()->flash('status', 'تم تسجيل انصرافك.');
        } catch (\RuntimeException $e) {
            $this->addError('attendance', $e->getMessage());
        }
    }

    public function render(AttendanceService $service)
    {
        // The employee dashboard is operational only — never any financial data.
        $user = Auth::user();
        $employee = $user->employee;

        $today = null;
        $summary = null;
        $pendingLeaves = 0;
        $tasks = ['today' => 0, 'late' => 0, 'waiting_review' => 0, 'changes_requested' => 0];

        if ($employee) {
            $today = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('attendance_date', now()->toDateString())
                ->first();
            $summary = $service->monthlySummary($employee, (int) now()->year, (int) now()->month);
            $pendingLeaves = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', LeaveStatus::Pending)->count();

            $visible = fn () => Task::query()->visibleTo($user);
            $tasks = [
                'today' => $visible()->whereDate('due_date', now()->toDateString())->count(),
                'late' => $visible()->late()->count(),
                'waiting_review' => $visible()->where('status', TaskStatus::WaitingReview)->count(),
                'changes_requested' => $visible()->where('status', TaskStatus::ChangesRequested)->count(),
            ];
        }

        return view('livewire.employee.dashboard', [
            'employee' => $employee,
            'today' => $today,
            'summary' => $summary,
            'pendingLeaves' => $pendingLeaves,
            'tasks' => $tasks,
        ]);
    }
}
