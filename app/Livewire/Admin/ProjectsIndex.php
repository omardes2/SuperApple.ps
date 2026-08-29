<?php

namespace App\Livewire\Admin;

use App\Enums\Priority;
use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('المشاريع')]
class ProjectsIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $customer = '';

    #[Url]
    public string $manager = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $priority = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $customer_id = null;

    public string $name = '';

    public string $description = '';

    public string $project_type = '';

    public ?int $project_manager_id = null;

    public ?int $department_id = null;

    public string $project_priority = 'normal';

    public string $project_status = 'draft';

    public ?string $start_date = null;

    public ?string $due_date = null;

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('projects.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'customer', 'manager', 'department', 'status', 'priority'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('projects.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('projects.edit');
        $p = Project::findOrFail($id);
        $this->editingId = $p->id;
        $this->customer_id = $p->customer_id;
        $this->name = $p->name;
        $this->description = (string) $p->description;
        $this->project_type = (string) $p->project_type;
        $this->project_manager_id = $p->project_manager_id;
        $this->department_id = $p->department_id;
        $this->project_priority = $p->priority->value;
        $this->project_status = $p->status->value;
        $this->start_date = $p->start_date?->toDateString();
        $this->due_date = $p->due_date?->toDateString();
        $this->notes = (string) $p->notes;
        $this->showForm = true;
    }

    public function save(ProjectService $service): void
    {
        $this->authorize($this->editingId ? 'projects.edit' : 'projects.create');

        $validated = $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'project_type' => 'nullable|string|max:100',
            'project_manager_id' => 'nullable|integer|exists:employees,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'project_priority' => ['required', Rule::enum(Priority::class)],
            'project_status' => ['required', Rule::enum(ProjectStatus::class)],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data = [
            'customer_id' => $validated['customer_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'project_type' => $validated['project_type'] ?? null,
            'project_manager_id' => $validated['project_manager_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'priority' => $validated['project_priority'],
            'status' => $validated['project_status'],
            'start_date' => $validated['start_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];

        if ($this->editingId) {
            $service->update(Project::findOrFail($this->editingId), $data);
            session()->flash('status', 'تم تحديث المشروع.');
        } else {
            $service->create($data);
            session()->flash('status', 'تم إنشاء المشروع.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'customer_id', 'name', 'description', 'project_type',
            'project_manager_id', 'department_id', 'start_date', 'due_date', 'notes',
        ]);
        $this->project_priority = 'normal';
        $this->project_status = 'draft';
        $this->resetErrorBag();
    }

    public function render()
    {
        $projects = Project::query()
            ->with(['customer', 'projectManager', 'department'])
            ->withCount('tasks')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('project_number', 'like', "%{$this->search}%")))
            ->when($this->customer !== '', fn ($q) => $q->where('customer_id', $this->customer))
            ->when($this->manager !== '', fn ($q) => $q->where('project_manager_id', $this->manager))
            ->when($this->department !== '', fn ($q) => $q->where('department_id', $this->department))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->priority !== '', fn ($q) => $q->where('priority', $this->priority))
            ->latest()
            ->paginate(15);

        $open = Project::open()->get(['status', 'due_date']);
        $stats = [
            'active' => Project::where('status', ProjectStatus::Active)->count(),
            'late' => $open->filter(fn ($p) => $p->due_date && $p->due_date->isPast())->count(),
            'under_review' => Project::where('status', ProjectStatus::UnderReview)->count(),
            'completed_month' => Project::where('status', ProjectStatus::Completed)
                ->whereMonth('completed_at', now()->month)->whereYear('completed_at', now()->year)->count(),
        ];

        return view('livewire.admin.projects-index', [
            'projects' => $projects,
            'stats' => $stats,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'managers' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'statusOptions' => ProjectStatus::options(),
            'priorityOptions' => Priority::options(),
        ]);
    }
}
