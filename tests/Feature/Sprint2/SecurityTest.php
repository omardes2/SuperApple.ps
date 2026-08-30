<?php

namespace Tests\Feature\Sprint2;

use App\Enums\RoleName;
use App\Livewire\Employee\MyTasks;
use App\Livewire\Employee\TaskShow;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_employee_has_no_service_financial_permission(): void
    {
        [$user] = $this->makeStaff();
        $this->assertFalse($user->can('services.view_financial'));
        $this->assertFalse($user->can('services.view'));
    }

    public function test_employee_cannot_reach_admin_operational_routes(): void
    {
        [$user] = $this->makeStaff();

        foreach (['/admin/customers', '/admin/tasks', '/admin/services'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('employee.dashboard'));
        }
    }

    public function test_employee_task_query_is_scoped_and_cannot_enumerate_all_tasks(): void
    {
        [$user, $employee] = $this->makeStaff();
        $mine = $this->makeTask(['primary_assignee_id' => $employee->id]);
        $this->makeTask();
        $this->makeTask();

        // The component's own query only returns the employee's task.
        Livewire::actingAs($user)->test(MyTasks::class)
            ->assertViewHas('tasks', fn ($tasks) => $tasks->total() === 1 && $tasks->first()->id === $mine->id);
    }

    public function test_employee_cannot_open_unrelated_task_detail(): void
    {
        [$user] = $this->makeStaff();
        $foreign = $this->makeTask();

        $this->actingAs($user)->get(route('employee.tasks.show', $foreign))->assertForbidden();
    }

    public function test_hr_manager_has_no_finance_or_project_admin(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);

        $this->assertFalse($hr->can('finance.view'));
        $this->assertFalse($hr->can('invoices.view'));
        $this->assertFalse($hr->can('projects.manage'));
        $this->assertFalse($hr->can('tasks.manage'));
        $this->assertFalse($hr->can('customers.manage'));
    }

    public function test_accountant_has_no_project_or_task_admin(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);

        $this->assertFalse($accountant->can('projects.manage'));
        $this->assertFalse($accountant->can('projects.create'));
        $this->assertFalse($accountant->can('tasks.manage'));
        $this->assertFalse($accountant->can('tasks.assign'));
        $this->assertFalse($accountant->can('tasks.review'));
    }

    public function test_project_manager_has_no_financial_permissions(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);

        foreach (['finance.view', 'invoices.view', 'payments.view', 'payroll.view', 'services.view_financial', 'reports.financial'] as $perm) {
            $this->assertFalse($pm->can($perm), "PM must not have [{$perm}]");
        }
    }

    private function revokeFromEmployeeRole(string $permission): void
    {
        Role::findByName(RoleName::Employee->value)->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_comment_requires_permission(): void
    {
        // Keep task visibility (view_own) but drop the comment ability.
        $this->revokeFromEmployeeRole('tasks.comment');
        [$user, $employee] = $this->makeStaff();
        $this->assertFalse($user->can('tasks.comment'));
        $task = $this->makeTask(['primary_assignee_id' => $employee->id]);

        Livewire::actingAs($user)->test(TaskShow::class, ['task' => $task])
            ->set('newComment', 'تعليق')
            ->call('addComment')
            ->assertForbidden();
    }

    public function test_checklist_requires_permission(): void
    {
        $this->revokeFromEmployeeRole('tasks.checklist');
        [$user, $employee] = $this->makeStaff();
        $this->assertFalse($user->can('tasks.checklist'));
        $task = $this->makeTask(['primary_assignee_id' => $employee->id]);

        Livewire::actingAs($user)->test(TaskShow::class, ['task' => $task])
            ->set('newChecklistItem', 'عنصر')
            ->call('addChecklistItem')
            ->assertForbidden();
    }

    public function test_assign_requires_permission(): void
    {
        [$user, $employee] = $this->makeStaff();
        $task = $this->makeTask(['primary_assignee_id' => $employee->id]);
        [, $other] = $this->makeStaff();

        // Employee lacks tasks.assign.
        Livewire::actingAs($user)->test(TaskShow::class, ['task' => $task])
            ->set('newAssigneeId', $other->id)
            ->call('addAssignee')
            ->assertForbidden();
    }
}
