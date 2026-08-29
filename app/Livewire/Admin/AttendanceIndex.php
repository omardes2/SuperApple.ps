<?php

namespace App\Livewire\Admin;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الدوام')]
class AttendanceIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $date;

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    // Adjustment modal
    public bool $showAdjust = false;

    public ?int $adjustId = null;

    public ?string $adjCheckIn = null;

    public ?string $adjCheckOut = null;

    public string $adjStatus = 'present';

    public string $adjNotes = '';

    public function mount(): void
    {
        $this->authorize('attendance.view');
        $this->date = now()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['date', 'department', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function openAdjust(int $id): void
    {
        $this->authorize('attendance.adjust');
        $record = AttendanceRecord::findOrFail($id);
        $this->adjustId = $record->id;
        $this->adjCheckIn = $record->check_in_at?->format('Y-m-d\TH:i');
        $this->adjCheckOut = $record->check_out_at?->format('Y-m-d\TH:i');
        $this->adjStatus = $record->status->value;
        $this->adjNotes = (string) $record->notes;
        $this->showAdjust = true;
    }

    public function saveAdjust(AttendanceService $service): void
    {
        $this->authorize('attendance.adjust');

        $data = $this->validate([
            'adjCheckIn' => 'nullable|date',
            'adjCheckOut' => 'nullable|date|after_or_equal:adjCheckIn',
            'adjStatus' => ['required', Rule::enum(AttendanceStatus::class)],
            'adjNotes' => 'nullable|string|max:500',
        ]);

        $record = AttendanceRecord::findOrFail($this->adjustId);
        $service->adjust($record, [
            'check_in_at' => $data['adjCheckIn'] ? Carbon::parse($data['adjCheckIn']) : null,
            'check_out_at' => $data['adjCheckOut'] ? Carbon::parse($data['adjCheckOut']) : null,
            'status' => $data['adjStatus'],
            'notes' => $data['adjNotes'] ?: null,
        ]);

        $this->showAdjust = false;
        session()->flash('status', 'تم تعديل سجل الدوام.');
    }

    public function render()
    {
        $records = AttendanceRecord::query()
            ->with('employee.department')
            ->whereDate('attendance_date', $this->date)
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->department !== '', fn ($q) => $q->whereHas('employee', fn ($q) => $q->where('department_id', $this->department)))
            ->when($this->search !== '', fn ($q) => $q->whereHas('employee', fn ($q) => $q
                ->where('full_name', 'like', "%{$this->search}%")
                ->orWhere('employee_number', 'like', "%{$this->search}%")))
            ->orderByDesc('check_in_at')
            ->paginate(20);

        $dayRecords = AttendanceRecord::whereDate('attendance_date', $this->date)->get();
        $activeCount = Employee::active()->count();
        $presentIds = $dayRecords->whereNotNull('check_in_at')->pluck('employee_id')->unique();

        $stats = [
            'present' => $presentIds->count(),
            'late' => $dayRecords->where('status', AttendanceStatus::Late)->count(),
            'on_leave' => $dayRecords->where('status', AttendanceStatus::Leave)->count(),
            'absent' => max(0, $activeCount - $presentIds->count() - $dayRecords->where('status', AttendanceStatus::Leave)->count()),
        ];

        return view('livewire.admin.attendance-index', [
            'records' => $records,
            'stats' => $stats,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'statusOptions' => AttendanceStatus::options(),
        ]);
    }
}
