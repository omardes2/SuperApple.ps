<?php

namespace App\Livewire\Admin;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تقارير المشاريع والمهام')]
class ProjectsReport extends Component
{
    public function mount(): void
    {
        $this->authorize('reports.projects');
    }

    public function render()
    {
        $projectsByStatus = Project::select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');
        $tasksByStatus = Task::select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');

        $totalTasks = (int) $tasksByStatus->sum();
        $completedTasks = (int) ($tasksByStatus[TaskStatus::Completed->value] ?? 0);
        $completionRate = $totalTasks > 0 ? round($completedTasks / $totalTasks * 100, 1) : 0.0;

        // Average completion time (days) for completed tasks with both timestamps.
        $avgDays = Task::whereNotNull('completed_at')->whereNotNull('start_date')
            ->get()->map(fn ($t) => $t->start_date->diffInDays($t->completed_at))->avg();

        return view('livewire.admin.projects-report', [
            'projectsByStatus' => $projectsByStatus,
            'tasksByStatus' => $tasksByStatus,
            'activeProjects' => (int) ($projectsByStatus[ProjectStatus::Active->value] ?? 0),
            'lateProjects' => Project::open()->get()->filter(fn ($p) => $p->isLate())->count(),
            'completionRate' => $completionRate,
            'lateTasks' => Task::late()->count(),
            'avgCompletionDays' => $avgDays !== null ? round($avgDays, 1) : null,
            'byManager' => Project::where('status', ProjectStatus::Active->value)->with('projectManager')
                ->select('project_manager_id', DB::raw('count(*) c'))->groupBy('project_manager_id')->get(),
            'byDepartment' => Project::where('status', ProjectStatus::Active->value)->with('department')
                ->select('department_id', DB::raw('count(*) c'))->groupBy('department_id')->get(),
            'statuses' => ProjectStatus::options(),
            'taskStatuses' => TaskStatus::options(),
        ]);
    }
}
