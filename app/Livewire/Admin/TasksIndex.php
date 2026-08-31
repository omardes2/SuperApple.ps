<?php

namespace App\Livewire\Admin;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\CreatesTasks;
use App\Models\Employee;
use App\Models\Task;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('المهام')]
class TasksIndex extends Component
{
    use CreatesTasks, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $assignee = '';

    #[Url]
    public string $customer = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $priority = '';

    public function mount(): void
    {
        $this->authorize('tasks.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'assignee', 'customer', 'department', 'status', 'priority'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $tasks = Task::query()
            ->with(['customer', 'primaryAssignee'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$this->search}%")
                ->orWhere('task_number', 'like', "%{$this->search}%")))
            ->when($this->assignee !== '', fn ($q) => $q->where('primary_assignee_id', $this->assignee))
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

        return view('livewire.admin.tasks-index', array_merge($this->taskFormViewData(), [
            'tasks' => $tasks,
            'stats' => $stats,
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'statusOptions' => TaskStatus::options(),
            'priorityOptions' => Priority::options(),
        ]));
    }
}
