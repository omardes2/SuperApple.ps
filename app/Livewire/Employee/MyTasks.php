<?php

namespace App\Livewire\Employee;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.employee')]
#[Title('مهامي')]
class MyTasks extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    public function mount(): void
    {
        $this->authorize('tasks.view_own');
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
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

        $query = $base()->with(['project', 'customer']);

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
        ]);
    }
}
