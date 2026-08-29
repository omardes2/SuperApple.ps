<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\ProjectStatus;
use App\Models\Employee;
use App\Models\Project;
use App\Notifications\ProjectMemberAdded;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Project
    {
        $data['project_number'] = $data['project_number'] ?? $this->numbers->next('project');
        $data['status'] = $data['status'] ?? ProjectStatus::Draft->value;
        $data['priority'] = $data['priority'] ?? Priority::Normal->value;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return Project::create($data);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        $data['updated_by'] = Auth::id();

        // Keep completed_at in step with the status.
        if (array_key_exists('status', $data)) {
            $status = $data['status'] instanceof ProjectStatus ? $data['status'] : ProjectStatus::from($data['status']);
            $data['completed_at'] = $status === ProjectStatus::Completed ? ($project->completed_at ?? now()) : null;
        }

        $project->update($data);

        return $project;
    }

    /**
     * Add an employee to a project. Duplicate membership is prevented by a
     * unique index; we surface a clean error instead.
     */
    public function addMember(Project $project, Employee $employee, ?string $role = null): void
    {
        if ($project->memberships()->where('employee_id', $employee->id)->exists()) {
            throw new RuntimeException('الموظف عضو في المشروع بالفعل.');
        }

        DB::transaction(function () use ($project, $employee, $role) {
            $project->memberships()->create([
                'employee_id' => $employee->id,
                'role' => $role,
                'joined_at' => now(),
            ]);
        });

        if ($employee->user) {
            $employee->user->notify(new ProjectMemberAdded($project));
        }
    }

    public function removeMember(Project $project, Employee $employee): void
    {
        $project->memberships()->where('employee_id', $employee->id)->delete();
    }

    /**
     * Cancel instead of hard-deleting so the operational history is kept.
     */
    public function cancel(Project $project): Project
    {
        $project->update(['status' => ProjectStatus::Cancelled, 'updated_by' => Auth::id()]);

        return $project;
    }
}
