<?php

namespace App\Services;

use App\Enums\TaskMemberStatus;
use App\Enums\TaskStatus;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AddedToTask;
use App\Notifications\TaskCompleted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The collaborative task workflow engine. Every task member (primary assignee +
 * participants) owns an independent execution state on the task_assignees pivot,
 * which is the single source of truth. The task is New until the first member
 * starts (→ InProgress) and only becomes Completed once every active member has
 * finished. There is no "waiting review" step in this workflow.
 *
 * All authorization lives here: a member may only start/complete their OWN part
 * (derived from the actor's user, never a passed id), and only a team manager
 * (creator/primary assignee, or a holder of tasks.assign/tasks.manage) may add
 * or remove participants.
 */
class TaskMemberService
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------ Authorization

    /**
     * Who may add/remove participants: a holder of tasks.assign or tasks.manage,
     * or the task's own creator / primary assignee. This does NOT grant the
     * global tasks.assign capability — it is a task-scoped ownership rule.
     */
    public function canManageTeam(Task $task, User $actor): bool
    {
        if ($actor->can('tasks.assign') || $actor->can('tasks.manage')) {
            return true;
        }

        if ((int) $task->created_by === (int) $actor->id) {
            return true;
        }

        $employeeId = $actor->employee?->id;

        return $employeeId !== null && (int) $task->primary_assignee_id === (int) $employeeId;
    }

    // ------------------------------------------------------------ Membership

    /**
     * Ensure an employee is an active member, attaching them if missing. Used to
     * seed the creator as the first team member. Idempotent.
     */
    public function ensureMember(Task $task, Employee $employee, string $role = 'participant', ?int $addedBy = null): void
    {
        $existing = $task->assignees()->where('employee_id', $employee->id)->first();

        if ($existing === null) {
            $task->assignees()->attach($employee->id, [
                'role' => $role,
                'assigned_at' => now(),
                'status' => TaskMemberStatus::NotStarted->value,
                'added_by' => $addedBy,
                'is_active' => true,
            ]);

            return;
        }

        // Reactivate a previously removed member without touching their history.
        if (! $existing->pivot->is_active) {
            $task->assignees()->updateExistingPivot($employee->id, ['is_active' => true]);
        }
    }

    public function addParticipant(Task $task, Employee $employee, User $actor): void
    {
        if (! $this->canManageTeam($task, $actor)) {
            throw new RuntimeException('ليس لديك صلاحية لإدارة فريق هذه المهمة.');
        }
        if (! $task->status->isOpen()) {
            throw new RuntimeException('لا يمكن تعديل الفريق بعد إغلاق المهمة.');
        }
        if (! $employee->is_active) {
            throw new RuntimeException('لا يمكن إضافة موظف غير نشط.');
        }

        $existing = $task->assignees()->where('employee_id', $employee->id)->first();
        if ($existing && $existing->pivot->is_active) {
            throw new RuntimeException('الموظف عضو في المهمة بالفعل.');
        }

        DB::transaction(function () use ($task, $employee, $actor) {
            $this->ensureMember($task, $employee, 'participant', $actor->id);

            $this->audit->log('task_member_added', $task, 'Tasks',
                new: ['employee_id' => $employee->id, 'name' => $employee->full_name],
                description: "إضافة {$employee->full_name} إلى فريق المهمة");

            $employee->user?->notify(new AddedToTask($task));
        });
    }

    public function removeParticipant(Task $task, Employee $employee, User $actor): void
    {
        if (! $this->canManageTeam($task, $actor)) {
            throw new RuntimeException('ليس لديك صلاحية لإدارة فريق هذه المهمة.');
        }
        if ((int) $task->primary_assignee_id === (int) $employee->id) {
            throw new RuntimeException('لا يمكن إزالة مسؤول المهمة من الفريق.');
        }

        $member = $task->assignees()->where('employee_id', $employee->id)->wherePivot('is_active', true)->first();
        if ($member === null) {
            throw new RuntimeException('الموظف ليس عضواً نشطاً في المهمة.');
        }

        DB::transaction(function () use ($task, $employee, $member) {
            $status = TaskMemberStatus::from($member->pivot->status);

            if ($status === TaskMemberStatus::NotStarted) {
                // Never started, no history to preserve → detach fully.
                $task->assignees()->detach($employee->id);
            } else {
                // Preserve the audit trail: disable the assignment, keep the row.
                $task->assignees()->updateExistingPivot($employee->id, ['is_active' => false]);
            }

            $this->audit->log('task_member_removed', $task, 'Tasks',
                old: ['employee_id' => $employee->id, 'name' => $employee->full_name],
                description: "إزالة {$employee->full_name} من فريق المهمة");
        });
    }

    // ------------------------------------------------------------ Execution

    /** The acting employee starts their own part of the task. */
    public function start(Task $task, User $actor): void
    {
        $employee = $this->assertActingMember($task, $actor);
        $status = TaskMemberStatus::from($task->memberFor($employee)->pivot->status);

        if ($status !== TaskMemberStatus::NotStarted) {
            return; // already started/completed — no-op
        }

        DB::transaction(function () use ($task, $employee, $actor) {
            $task->assignees()->updateExistingPivot($employee->id, [
                'status' => TaskMemberStatus::InProgress->value,
                'started_at' => now(),
            ]);

            $this->audit->log('task_member_started', $task, 'Tasks',
                new: ['employee_id' => $employee->id],
                description: "{$employee->full_name} بدأ العمل في المهمة");

            // First member to start moves the whole task into progress.
            if ($task->status === TaskStatus::New) {
                $this->moveTask($task, TaskStatus::InProgress, $actor);
            }
        });
    }

    /** The acting employee completes their own part of the task. */
    public function complete(Task $task, User $actor): void
    {
        $employee = $this->assertActingMember($task, $actor);
        $status = TaskMemberStatus::from($task->memberFor($employee)->pivot->status);

        if ($status === TaskMemberStatus::Completed) {
            return;
        }
        if ($status === TaskMemberStatus::NotStarted) {
            throw new RuntimeException('يجب بدء المهمة قبل إتمامها.');
        }

        DB::transaction(function () use ($task, $employee, $actor) {
            $task->assignees()->updateExistingPivot($employee->id, [
                'status' => TaskMemberStatus::Completed->value,
                'completed_at' => now(),
            ]);

            $this->audit->log('task_member_completed', $task, 'Tasks',
                new: ['employee_id' => $employee->id],
                description: "{$employee->full_name} أتمّ عمله في المهمة");

            $this->recomputeCompletion($task, $actor);
        });
    }

    /**
     * Close the task once every active member has completed. Called after a
     * member completes; safe to call at any time.
     */
    public function recomputeCompletion(Task $task, User $actor): void
    {
        $task->load('activeMembers');
        $members = $task->activeMembers;

        if ($members->isEmpty() || ! $task->status->isOpen()) {
            return;
        }

        $allDone = $members->every(fn (Employee $m) => $m->pivot->status === TaskMemberStatus::Completed->value);

        if ($allDone) {
            $this->moveTask($task, TaskStatus::Completed, $actor);
            $this->notifyCompletion($task);
        }
    }

    // ------------------------------------------------------------ Internals

    private function assertActingMember(Task $task, User $actor): Employee
    {
        $employee = $actor->employee;

        if ($employee === null || ! $task->isActiveMember($employee)) {
            throw new RuntimeException('لست عضواً في هذه المهمة.');
        }

        return $employee;
    }

    private function moveTask(Task $task, TaskStatus $to, User $actor): void
    {
        $from = $task->status;
        if ($from === $to) {
            return;
        }

        $task->status = $to;
        $task->completed_at = $to === TaskStatus::Completed ? now() : $task->completed_at;
        $task->updated_by = $actor->id;
        $task->save();

        $task->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $actor->id,
            'reason' => null,
            'created_at' => now(),
        ]);

        $this->audit->log('task_status_changed', $task, 'Tasks',
            old: ['status' => $from->value], new: ['status' => $to->value],
            description: "تغيير حالة المهمة من {$from->label()} إلى {$to->label()}");
    }

    private function notifyCompletion(Task $task): void
    {
        $recipients = collect();

        $creator = User::find($task->created_by);
        if ($creator) {
            $recipients->push($creator);
        }
        $recipients = $recipients->merge(User::permission('tasks.manage')->get());

        foreach ($recipients->unique('id') as $user) {
            $user->notify(new TaskCompleted($task));
        }
    }
}
