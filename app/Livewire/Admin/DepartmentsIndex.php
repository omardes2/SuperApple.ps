<?php

namespace App\Livewire\Admin;

use App\Models\Department;
use App\Models\Employee;
use App\Services\DepartmentService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الأقسام')]
class DepartmentsIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $code = '';

    public string $description = '';

    public ?int $manager_id = null;

    public bool $is_active = true;

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->authorize('departments.view');
    }

    public function create(): void
    {
        $this->authorize('departments.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('departments.edit');
        $department = Department::findOrFail($id);
        $this->editingId = $department->id;
        $this->name = $department->name;
        $this->code = $department->code;
        $this->description = (string) $department->description;
        $this->manager_id = $department->manager_id;
        $this->is_active = $department->is_active;
        $this->sort_order = $department->sort_order;
        $this->showForm = true;
    }

    public function save(DepartmentService $service): void
    {
        $this->authorize($this->editingId ? 'departments.edit' : 'departments.create');

        $data = $this->validate([
            'name' => 'required|string|max:150',
            'code' => ['required', 'string', 'max:50', Rule::unique('departments', 'code')->ignore($this->editingId)],
            'description' => 'nullable|string|max:1000',
            'manager_id' => 'nullable|integer|exists:employees,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        if ($this->editingId) {
            $service->update(Department::findOrFail($this->editingId), $data);
            session()->flash('status', 'تم تحديث القسم.');
        } else {
            $service->create($data);
            session()->flash('status', 'تم إنشاء القسم.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleActive(int $id, DepartmentService $service): void
    {
        $this->authorize('departments.edit');
        $department = Department::findOrFail($id);
        $service->update($department, ['is_active' => ! $department->is_active]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'code', 'description', 'manager_id', 'sort_order']);
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $departments = Department::query()
            ->withCount('employees')
            ->with('manager')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(15);

        $managers = Employee::active()->orderBy('full_name')->get(['id', 'full_name']);

        return view('livewire.admin.departments-index', compact('departments', 'managers'));
    }
}
