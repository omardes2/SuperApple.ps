<?php

namespace App\Livewire\Admin;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الموظفون')]
class EmployeesIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $full_name = '';

    public ?string $employee_number = null;

    public string $phone = '';

    public string $job_title = '';

    public ?int $department_id = null;

    public ?int $direct_manager_id = null;

    public ?string $hire_date = null;

    public string $employment_status = 'active';

    public string $employment_type = 'full_time';

    public string $working_hours_per_day = '8';

    public string $notes = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('employees.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'department', 'status', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('employees.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('employees.edit');
        $employee = Employee::findOrFail($id);
        $this->editingId = $employee->id;
        $this->full_name = $employee->full_name;
        $this->employee_number = $employee->employee_number;
        $this->phone = (string) $employee->phone;
        $this->job_title = (string) $employee->job_title;
        $this->department_id = $employee->department_id;
        $this->direct_manager_id = $employee->direct_manager_id;
        $this->hire_date = $employee->hire_date?->toDateString();
        $this->employment_status = $employee->employment_status->value;
        $this->employment_type = $employee->employment_type->value;
        $this->working_hours_per_day = (string) $employee->working_hours_per_day;
        $this->notes = (string) $employee->notes;
        $this->is_active = $employee->is_active;
        $this->showForm = true;
    }

    public function save(EmployeeService $service): void
    {
        $this->authorize($this->editingId ? 'employees.edit' : 'employees.create');

        $data = $this->validate([
            'full_name' => 'required|string|max:150',
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_number')->ignore($this->editingId)],
            'phone' => 'nullable|string|max:40',
            'job_title' => 'nullable|string|max:120',
            'department_id' => 'nullable|integer|exists:departments,id',
            'direct_manager_id' => 'nullable|integer|exists:employees,id',
            'hire_date' => 'nullable|date',
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'working_hours_per_day' => 'required|numeric|min:0|max:24',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
        ]);

        try {
            if ($this->editingId) {
                $service->update(Employee::findOrFail($this->editingId), $data);
                session()->flash('status', 'تم تحديث الموظف.');
            } else {
                $service->create($data);
                session()->flash('status', 'تم إضافة الموظف.');
            }
        } catch (\RuntimeException $e) {
            $this->addError('direct_manager_id', $e->getMessage());

            return;
        }

        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'full_name', 'employee_number', 'phone', 'job_title',
            'department_id', 'direct_manager_id', 'hire_date', 'notes',
        ]);
        $this->employment_status = 'active';
        $this->employment_type = 'full_time';
        $this->working_hours_per_day = '8';
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $today = now()->toDateString();

        $employees = Employee::query()
            ->with(['department', 'directManager'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$this->search}%")
                ->orWhere('employee_number', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->when($this->department !== '', fn ($q) => $q->where('department_id', $this->department))
            ->when($this->status !== '', fn ($q) => $q->where('employment_status', $this->status))
            ->when($this->type !== '', fn ($q) => $q->where('employment_type', $this->type))
            ->orderBy('full_name')
            ->paginate(15);

        $presentToday = AttendanceRecord::whereDate('attendance_date', $today)
            ->whereNotNull('check_in_at')->distinct('employee_id')->count('employee_id');
        $activeCount = Employee::active()->count();

        $stats = [
            'total' => Employee::count(),
            'active' => $activeCount,
            'present' => $presentToday,
            'absent' => max(0, $activeCount - $presentToday),
        ];

        return view('livewire.admin.employees-index', [
            'employees' => $employees,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'managers' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'stats' => $stats,
            'statusOptions' => EmploymentStatus::options(),
            'typeOptions' => EmploymentType::options(),
        ]);
    }
}
