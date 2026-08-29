<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\ResolvesActingEmployee;
use App\Models\AttendanceRecord;
use App\Services\AttendanceService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.employee')]
#[Title('دوامي')]
class MyAttendance extends Component
{
    use ResolvesActingEmployee;

    public int $month;

    public int $year;

    public function mount(): void
    {
        $this->authorize('attendance.view_own');
        $this->month = (int) now()->month;
        $this->year = (int) now()->year;
    }

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
        $employee = $this->actingEmployee();

        $today = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('attendance_date', now()->toDateString())
            ->first();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereYear('attendance_date', $this->year)
            ->whereMonth('attendance_date', $this->month)
            ->orderByDesc('attendance_date')
            ->get();

        return view('livewire.employee.my-attendance', [
            'today' => $today,
            'records' => $records,
            'summary' => $service->monthlySummary($employee, $this->year, $this->month),
        ]);
    }
}
