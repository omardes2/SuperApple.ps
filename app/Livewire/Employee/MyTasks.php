<?php

namespace App\Livewire\Employee;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ResolvesActingEmployee;
use App\Models\Customer;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.employee')]
#[Title('مهامي')]
class MyTasks extends Component
{
    use ResolvesActingEmployee, WithPagination;

    #[Url]
    public string $filter = 'all';

    // ---- New-task form (employee self-service) ----
    public bool $showForm = false;

    public string $title = '';

    public string $description = '';

    public ?int $customer_id = null;

    public string $task_priority = 'normal';

    public ?string $start_date = null;

    public ?string $due_date = null;

    public function mount(): void
    {
        $this->authorize('tasks.view_own');
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('tasks.create');
        $this->reset(['title', 'description', 'customer_id', 'start_date', 'due_date']);
        $this->task_priority = 'normal';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(TaskService $service): void
    {
        // Backend gate — never trust the button alone.
        $this->authorize('tasks.create');
        $employee = $this->actingEmployee();

        $rules = [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'task_priority' => ['required', Rule::enum(Priority::class)],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
        ];
        // A customer may only be attached by someone allowed to see customers.
        if (Auth::user()->can('customers.view')) {
            $rules['customer_id'] = 'nullable|integer|exists:customers,id';
        }
        $validated = $this->validate($rules);

        $service->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'customer_id' => Auth::user()->can('customers.view') ? ($validated['customer_id'] ?? null) : null,
            // The employee's own department, and self-assignment: an employee
            // without tasks.assign can only create work for themselves.
            'department_id' => $employee->department_id,
            'primary_assignee_id' => $employee->id,
            'priority' => $validated['task_priority'],
            'start_date' => $validated['start_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        $this->showForm = false;
        session()->flash('status', 'تم إنشاء المهمة.');
    }

    public function render()
    {
        $user = Auth::user();

        // Each call returns a fresh query scoped to the user's visible tasks.
        $base = fn () => Task::query()->visibleTo($user);

        $counts = [
            'all' => $base()->count(),
            'today' => $base()->whereDate('due_date', now()->toDateString())->count(),
            'late' => $base()->late()->count(),
            'in_progress' => $base()->where('status', TaskStatus::InProgress)->count(),
            'waiting_review' => $base()->where('status', TaskStatus::WaitingReview)->count(),
            'changes_requested' => $base()->where('status', TaskStatus::ChangesRequested)->count(),
            'completed' => $base()->where('status', TaskStatus::Completed)->count(),
        ];

        $query = $base()->with('customer');

        $query = match ($this->filter) {
            'today' => $query->whereDate('due_date', now()->toDateString()),
            'late' => $query->late(),
            'in_progress' => $query->where('status', TaskStatus::InProgress),
            'waiting_review' => $query->where('status', TaskStatus::WaitingReview),
            'changes_requested' => $query->where('status', TaskStatus::ChangesRequested),
            'completed' => $query->where('status', TaskStatus::Completed),
            default => $query,
        };

        return view('livewire.employee.my-tasks', [
            'tasks' => $query->orderByRaw('due_date is null, due_date asc')->paginate(15),
            'counts' => $counts,
            'canCreate' => $user->can('tasks.create'),
            'customers' => $user->can('customers.view')
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'priorityOptions' => Priority::options(),
        ]);
    }
}
