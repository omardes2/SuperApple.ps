<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Notifications\TaskAssigned;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TaskService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly TaskMemberService $members,
    ) {}

    /**
     * @param  array<string,mixed>  $data  May include `service_ids` (list<int>)
     *                                     and ad-budget fields.
     */
    public function create(array $data): Task
    {
        $serviceIds = $this->normalizeServiceIds($data['service_ids'] ?? []);
        unset($data['service_ids']);

        $data = $this->reconcileCustomerProject($data);
        $data['task_number'] = $data['task_number'] ?? $this->numbers->next('task');
        $data['status'] = $data['status'] ?? TaskStatus::New->value;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return DB::transaction(function () use ($data, $serviceIds) {
            $task = Task::create($data);

            if ($serviceIds !== []) {
                $task->services()->sync($serviceIds);
            }

            // The primary assignee is always the first active team member with
            // their own independent execution state.
            if ($task->primary_assignee_id) {
                $primary = Employee::find($task->primary_assignee_id);
                if ($primary) {
                    $this->members->ensureMember($task, $primary, 'owner', Auth::id());
                }
                $this->notifyAssignee($task, (int) $task->primary_assignee_id);
            }

            return $task->load('services', 'activeMembers');
        });
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private function normalizeServiceIds($ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Task $task, array $data): Task
    {
        $data = $this->reconcileCustomerProject($data, $task);
        $data['updated_by'] = Auth::id();

        $previousAssignee = $task->primary_assignee_id;
        $task->update($data);

        if (array_key_exists('primary_assignee_id', $data)
            && $task->primary_assignee_id
            && $task->primary_assignee_id !== $previousAssignee) {
            $this->notifyAssignee($task, (int) $task->primary_assignee_id);
        }

        return $task;
    }

    /**
     * Ensure a task's customer always matches its project's customer. When a
     * project is set the customer is derived from it; a conflicting explicit
     * customer is rejected.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function reconcileCustomerProject(array $data, ?Task $task = null): array
    {
        $projectId = $data['project_id'] ?? $task?->project_id;

        if (! $projectId) {
            return $data;
        }

        $project = Project::find($projectId);
        if (! $project) {
            return $data;
        }

        if (array_key_exists('customer_id', $data)
            && $data['customer_id']
            && (int) $data['customer_id'] !== (int) $project->customer_id) {
            throw new RuntimeException('عميل المهمة يجب أن يطابق عميل المشروع.');
        }

        $data['customer_id'] = $project->customer_id;

        return $data;
    }

    public function addAssignee(Task $task, Employee $employee, ?string $role = null): void
    {
        if ($task->assignees()->where('employee_id', $employee->id)->exists()) {
            throw new RuntimeException('الموظف مُسند لهذه المهمة بالفعل.');
        }

        $task->assignees()->attach($employee->id, ['role' => $role, 'assigned_at' => now()]);
        $this->notifyAssignee($task, (int) $employee->id);
    }

    public function removeAssignee(Task $task, Employee $employee): void
    {
        $task->assignees()->detach($employee->id);
    }

    public function addComment(Task $task, string $comment, ?int $parentId = null): TaskComment
    {
        return $task->comments()->getModel()->newQuery()->create([
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'comment' => $comment,
            'parent_id' => $parentId,
        ]);
    }

    public function addChecklistItem(Task $task, string $title): TaskChecklistItem
    {
        $sort = (int) $task->checklistItems()->max('sort_order') + 1;

        return $task->checklistItems()->create(['title' => $title, 'sort_order' => $sort]);
    }

    public function toggleChecklistItem(TaskChecklistItem $item): TaskChecklistItem
    {
        $done = ! $item->is_completed;
        $item->update([
            'is_completed' => $done,
            'completed_by' => $done ? Auth::id() : null,
            'completed_at' => $done ? now() : null,
        ]);

        return $item;
    }

    private function notifyAssignee(Task $task, int $employeeId): void
    {
        $employee = Employee::with('user')->find($employeeId);
        if ($employee?->user) {
            $employee->user->notify(new TaskAssigned($task));
        }
    }
}
