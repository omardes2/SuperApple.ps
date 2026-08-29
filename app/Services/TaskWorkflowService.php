<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskStatusChanged;
use App\Notifications\TaskSubmittedForReview;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The task workflow engine. Validates every status transition against both the
 * allowed state graph and the actor's permission/assignment, records a
 * dedicated status-history row (not just the audit log), and fires the right
 * notifications. Business logic lives here, never in components.
 */
class TaskWorkflowService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The allowed state graph: from-status => list of reachable to-statuses.
     *
     * @return array<string,list<TaskStatus>>
     */
    public static function graph(): array
    {
        return [
            TaskStatus::New->value => [TaskStatus::InProgress, TaskStatus::Cancelled],
            TaskStatus::InProgress->value => [TaskStatus::WaitingReview, TaskStatus::Cancelled],
            TaskStatus::WaitingReview->value => [TaskStatus::Completed, TaskStatus::ChangesRequested, TaskStatus::Cancelled],
            TaskStatus::ChangesRequested->value => [TaskStatus::InProgress, TaskStatus::Cancelled],
            TaskStatus::Completed->value => [TaskStatus::InProgress], // reopen
            TaskStatus::Cancelled->value => [],
        ];
    }

    /**
     * Whether `$to` is reachable from the task's current status at all.
     */
    public function isValidTransition(TaskStatus $from, TaskStatus $to): bool
    {
        return in_array($to, self::graph()[$from->value] ?? [], true);
    }

    /**
     * Whether the actor is permitted to perform this specific transition.
     */
    public function canTransition(Task $task, TaskStatus $to, User $actor): bool
    {
        $from = $task->status;

        if (! $this->isValidTransition($from, $to)) {
            return false;
        }

        $isAssignee = $task->isAssignedTo($actor->employee);
        $canManage = $actor->can('tasks.manage');
        $canReview = $actor->can('tasks.review');

        return match (true) {
            // Assignee-driven forward moves (or a manager on their behalf).
            $from === TaskStatus::New && $to === TaskStatus::InProgress => $isAssignee || $canManage,
            $from === TaskStatus::InProgress && $to === TaskStatus::WaitingReview => $isAssignee || $canManage,
            $from === TaskStatus::ChangesRequested && $to === TaskStatus::InProgress => $isAssignee || $canManage,

            // Review decisions require the review capability.
            $from === TaskStatus::WaitingReview && $to === TaskStatus::Completed => $canReview,
            $from === TaskStatus::WaitingReview && $to === TaskStatus::ChangesRequested => $canReview,

            // Reopen a completed task.
            $from === TaskStatus::Completed && $to === TaskStatus::InProgress => $canReview || $canManage,

            // Cancellation is a management action.
            $to === TaskStatus::Cancelled => $canManage,

            default => false,
        };
    }

    /**
     * Perform a transition. Throws when it is invalid or unauthorised, or when a
     * required reason is missing (changes-requested and reopen).
     */
    public function transition(Task $task, TaskStatus $to, User $actor, ?string $reason = null): Task
    {
        $from = $task->status;

        if (! $this->isValidTransition($from, $to)) {
            throw new RuntimeException("انتقال غير صالح من {$from->label()} إلى {$to->label()}.");
        }

        if (! $this->canTransition($task, $to, $actor)) {
            throw new RuntimeException('ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        }

        $reasonRequired = ($from === TaskStatus::WaitingReview && $to === TaskStatus::ChangesRequested)
            || ($from === TaskStatus::Completed && $to === TaskStatus::InProgress);

        if ($reasonRequired && blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب لهذا الإجراء.');
        }

        return DB::transaction(function () use ($task, $from, $to, $actor, $reason) {
            $task->status = $to;
            $task->completed_at = $to === TaskStatus::Completed ? now() : null;
            $task->updated_by = $actor->id;
            $task->save();

            $task->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => $to->value,
                'changed_by' => $actor->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->audit->log(
                action: 'task_status_changed',
                subject: $task,
                module: 'Tasks',
                old: ['status' => $from->value],
                new: ['status' => $to->value],
                description: "تغيير حالة المهمة من {$from->label()} إلى {$to->label()}".($reason ? " — {$reason}" : ''),
            );

            $this->notify($task, $from, $to);

            return $task;
        });
    }

    private function notify(Task $task, TaskStatus $from, TaskStatus $to): void
    {
        // Notify reviewers when a task enters review.
        if ($to === TaskStatus::WaitingReview) {
            $reviewers = User::permission('tasks.review')->get();
            foreach ($reviewers as $reviewer) {
                $reviewer->notify(new TaskSubmittedForReview($task));
            }
        }

        // Notify the assignee about decisions on their task.
        if (in_array($to, [TaskStatus::ChangesRequested, TaskStatus::Completed, TaskStatus::InProgress, TaskStatus::Cancelled], true)) {
            $user = $task->primaryAssignee?->user;
            if ($user) {
                $user->notify(new TaskStatusChanged($task, $from, $to));
            }
        }
    }
}
