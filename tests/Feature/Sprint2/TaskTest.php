<?php

namespace Tests\Feature\Sprint2;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function workflow(): TaskWorkflowService
    {
        return app(TaskWorkflowService::class);
    }

    /**
     * A task assigned to a fresh employee, plus that employee's user.
     *
     * @return array{0: Task, 1: User, 2: Employee}
     */
    private function assignedTask(array $attrs = []): array
    {
        [$user, $employee] = $this->makeStaff();
        $task = $this->makeTask(array_merge(['primary_assignee_id' => $employee->id], $attrs));

        return [$task, $user, $employee];
    }

    public function test_task_can_be_created_with_auto_number(): void
    {
        $this->actingAs($this->makeUser(RoleName::ProjectManager));

        $task = app(TaskService::class)->create(['title' => 'تصميم الشعار']);

        $this->assertStringStartsWith('TSK-', $task->task_number);
        $this->assertSame(TaskStatus::New, $task->status);
    }

    public function test_task_customer_must_match_project_customer(): void
    {
        $this->actingAs($this->makeUser(RoleName::ProjectManager));
        $projectCustomer = $this->makeCustomer();
        $project = $this->makeProject($projectCustomer);
        $otherCustomer = $this->makeCustomer();

        $this->expectException(RuntimeException::class);
        app(TaskService::class)->create([
            'title' => 'مهمة',
            'project_id' => $project->id,
            'customer_id' => $otherCustomer->id, // conflicts with project's customer
        ]);
    }

    public function test_task_customer_is_derived_from_project(): void
    {
        $this->actingAs($this->makeUser(RoleName::ProjectManager));
        $project = $this->makeProject();

        $task = app(TaskService::class)->create(['title' => 'مهمة', 'project_id' => $project->id]);

        $this->assertSame($project->customer_id, $task->customer_id);
    }

    public function test_employee_sees_own_assigned_task_but_not_others(): void
    {
        [$task, $user] = $this->assignedTask();
        $foreign = $this->makeTask();

        $this->assertTrue($task->isVisibleTo($user));
        $this->assertFalse($foreign->isVisibleTo($user));
        $this->actingAs($user)->get(route('employee.tasks.show', $foreign))->assertForbidden();
    }

    public function test_employee_can_start_and_submit_their_task(): void
    {
        [$task, $user] = $this->assignedTask();

        $this->workflow()->transition($task, TaskStatus::InProgress, $user);
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        $this->workflow()->transition($task->fresh(), TaskStatus::WaitingReview, $user);
        $this->assertSame(TaskStatus::WaitingReview, $task->fresh()->status);
    }

    public function test_employee_cannot_approve_their_own_task(): void
    {
        [$task, $user] = $this->assignedTask();
        $this->workflow()->transition($task, TaskStatus::InProgress, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::WaitingReview, $user);

        $this->expectException(RuntimeException::class);
        // Employee lacks tasks.review.
        $this->workflow()->transition($task->fresh(), TaskStatus::Completed, $user);
    }

    public function test_reviewer_can_approve_waiting_review_task(): void
    {
        [$task, $user] = $this->assignedTask();
        $pm = $this->makeUser(RoleName::ProjectManager);

        $this->workflow()->transition($task, TaskStatus::InProgress, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::WaitingReview, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::Completed, $pm);

        $done = $task->fresh();
        $this->assertSame(TaskStatus::Completed, $done->status);
        $this->assertNotNull($done->completed_at);
    }

    public function test_reviewer_can_request_changes_and_task_returns_to_in_progress(): void
    {
        [$task, $user] = $this->assignedTask();
        $pm = $this->makeUser(RoleName::ProjectManager);

        $this->workflow()->transition($task, TaskStatus::InProgress, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::WaitingReview, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::ChangesRequested, $pm, 'عدّل الألوان');
        $this->assertSame(TaskStatus::ChangesRequested, $task->fresh()->status);

        $this->workflow()->transition($task->fresh(), TaskStatus::InProgress, $user);
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_request_changes_requires_a_reason(): void
    {
        [$task, $user] = $this->assignedTask();
        $pm = $this->makeUser(RoleName::ProjectManager);
        $this->workflow()->transition($task, TaskStatus::InProgress, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::WaitingReview, $user);

        $this->expectException(RuntimeException::class);
        $this->workflow()->transition($task->fresh(), TaskStatus::ChangesRequested, $pm, null);
    }

    public function test_completed_task_can_be_reopened_only_by_authorized_user_with_reason(): void
    {
        [$task, $user] = $this->assignedTask();
        $pm = $this->makeUser(RoleName::ProjectManager);
        $this->workflow()->transition($task, TaskStatus::InProgress, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::WaitingReview, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::Completed, $pm);

        // Assignee (no review perm) cannot reopen.
        try {
            $this->workflow()->transition($task->fresh(), TaskStatus::InProgress, $user, 'أعد الفتح');
            $this->fail('employee should not reopen');
        } catch (RuntimeException $e) {
            $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        }

        // Authorized reviewer can, and the reason is stored.
        $this->workflow()->transition($task->fresh(), TaskStatus::InProgress, $pm, 'أعد الفتح للتعديل');
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertDatabaseHas('task_status_history', [
            'task_id' => $task->id,
            'to_status' => TaskStatus::InProgress->value,
            'reason' => 'أعد الفتح للتعديل',
        ]);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        [$task] = $this->assignedTask();
        $pm = $this->makeUser(RoleName::ProjectManager);

        // New -> Completed is not a valid edge.
        $this->expectException(RuntimeException::class);
        $this->workflow()->transition($task, TaskStatus::Completed, $pm);
    }

    public function test_every_transition_is_recorded_in_status_history(): void
    {
        [$task, $user] = $this->assignedTask();
        $pm = $this->makeUser(RoleName::ProjectManager);

        $this->workflow()->transition($task, TaskStatus::InProgress, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::WaitingReview, $user);
        $this->workflow()->transition($task->fresh(), TaskStatus::Completed, $pm);

        $this->assertSame(3, $task->statusHistory()->count());
    }

    public function test_late_scope_matches_overdue_open_tasks(): void
    {
        $this->makeTask(['due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::InProgress->value]);
        $this->makeTask(['due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::Completed->value]); // not late
        $this->makeTask(['due_date' => now()->addDays(2)->toDateString(), 'status' => TaskStatus::New->value]); // not late

        $this->assertSame(1, Task::late()->count());
    }

    public function test_duplicate_task_assignee_is_prevented(): void
    {
        $this->actingAs($this->makeUser(RoleName::ProjectManager));
        $task = $this->makeTask();
        [, $employee] = $this->makeStaff();
        $service = app(TaskService::class);

        $service->addAssignee($task, $employee);

        $this->expectException(RuntimeException::class);
        $service->addAssignee($task, $employee);
    }
}
