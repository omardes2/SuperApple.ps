<?php

namespace App\Livewire\Admin;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('المهام')]
class TasksIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $assignee = '';

    #[Url]
    public string $project = '';

    #[Url]
    public string $customer = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $priority = '';

    public bool $showForm = false;

    public string $title = '';

    public string $description = '';

    public ?int $project_id = null;

    public ?int $customer_id = null;

    public ?int $department_id = null;

    public ?int $primary_assignee_id = null;

    public string $task_priority = 'normal';

    public ?string $start_date = null;

    public ?string $due_date = null;

    public ?string $estimated_minutes = null;

    public function mount(): void
    {
        $this->authorize('tasks.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'assignee', 'project', 'customer', 'department', 'status', 'priority'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('tasks.create');
        $this->reset(['title', 'description', 'project_id', 'customer_id', 'department_id', 'primary_assignee_id', 'start_date', 'due_date', 'estimated_minutes']);
        $this->task_priority = 'normal';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(TaskService $service): void
    {
        $this->authorize('tasks.create');

        $validated = $this->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'project_id' => 'nullable|integer|exists:projects,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'primary_assignee_id' => 'nullable|integer|exists:employees,id',
            'task_priority' => ['required', Rule::enum(Priority::class)],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'estimated_minutes' => 'nullable|integer|min:0',
        ]);

        try {
            $service->create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'project_id' => $validated['project_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'primary_assignee_id' => $validated['primary_assignee_id'] ?? null,
                'priority' => $validated['task_priority'],
                'start_date' => $validated['start_date'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'estimated_minutes' => $validated['estimated_minutes'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            $this->addError('customer_id', $e->getMessage());

            return;
        }

        $this->showForm = false;
        session()->flash('status', 'تم إنشاء المهمة.');
    }

    public function render()
    {
        $tasks = Task::query()
            ->with(['customer', 'project', 'primaryAssignee'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$this->search}%")
                ->orWhere('task_number', 'like', "%{$this->search}%")))
            ->when($this->assignee !== '', fn ($q) => $q->where('primary_assignee_id', $this->assignee))
            ->when($this->project !== '', fn ($q) => $q->where('project_id', $this->project))
            ->when($this->customer !== '', fn ($q) => $q->where('customer_id', $this->customer))
            ->when($this->department !== '', fn ($q) => $q->where('department_id', $this->department))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->priority !== '', fn ($q) => $q->where('priority', $this->priority))
            ->latest()
            ->paginate(20);

        $stats = [
            'new' => Task::where('status', TaskStatus::New)->count(),
            'in_progress' => Task::where('status', TaskStatus::InProgress)->count(),
            'waiting_review' => Task::where('status', TaskStatus::WaitingReview)->count(),
            'late' => Task::late()->count(),
            'completed_today' => Task::where('status', TaskStatus::Completed)->whereDate('completed_at', now()->toDateString())->count(),
        ];

        return view('livewire.admin.tasks-index', [
            'tasks' => $tasks,
            'stats' => $stats,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name', 'customer_id']),
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'statusOptions' => TaskStatus::options(),
            'priorityOptions' => Priority::options(),
        ]);
    }
}
