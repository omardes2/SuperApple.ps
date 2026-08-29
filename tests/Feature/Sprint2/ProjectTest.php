<?php

namespace Tests\Feature\Sprint2;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_project_can_be_created_with_auto_number(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer();

        $project = app(ProjectService::class)->create([
            'customer_id' => $customer->id,
            'name' => 'هوية بصرية',
        ]);

        $this->assertNotNull($project->project_number);
        $this->assertStringStartsWith('PRJ-', $project->project_number);
        $this->assertSame($customer->id, $project->customer_id);
    }

    public function test_project_member_can_view_assigned_project(): void
    {
        [$user, $employee] = $this->makeStaff();
        $project = $this->makeProject();
        $project->memberships()->create(['employee_id' => $employee->id, 'joined_at' => now()]);

        $this->assertTrue($project->isVisibleTo($user));
        $this->assertTrue(Project::visibleTo($user)->get()->contains($project->id));
    }

    public function test_employee_cannot_view_unrelated_project(): void
    {
        [$user] = $this->makeStaff();
        $project = $this->makeProject();

        $this->assertFalse($project->isVisibleTo($user));
        $this->actingAs($user)->get(route('employee.projects.show', $project))->assertForbidden();
    }

    public function test_project_manager_can_view_all_projects(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        $this->makeProject();
        $this->makeProject();

        $this->assertSame(2, Project::visibleTo($pm)->count());
    }

    public function test_duplicate_project_member_is_prevented(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $project = $this->makeProject();
        [, $employee] = $this->makeStaff();
        $service = app(ProjectService::class);

        $service->addMember($project, $employee);

        $this->expectException(RuntimeException::class);
        $service->addMember($project, $employee);
    }

    public function test_project_progress_is_calculated_from_tasks(): void
    {
        $project = $this->makeProject();

        // 4 tasks: 2 completed, 1 open, 1 cancelled (excluded).
        $this->makeTask(['project_id' => $project->id, 'customer_id' => $project->customer_id, 'status' => TaskStatus::Completed->value]);
        $this->makeTask(['project_id' => $project->id, 'customer_id' => $project->customer_id, 'status' => TaskStatus::Completed->value]);
        $this->makeTask(['project_id' => $project->id, 'customer_id' => $project->customer_id, 'status' => TaskStatus::InProgress->value]);
        $this->makeTask(['project_id' => $project->id, 'customer_id' => $project->customer_id, 'status' => TaskStatus::Cancelled->value]);

        // 2 completed of 3 counted (cancelled excluded) = 67%.
        $this->assertSame(67, $project->fresh()->progress());
    }

    public function test_project_progress_is_zero_without_tasks(): void
    {
        $project = $this->makeProject();
        $this->assertSame(0, $project->progress());
    }

    public function test_cancel_keeps_project_record(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $project = $this->makeProject();

        app(ProjectService::class)->cancel($project);

        $this->assertSame(ProjectStatus::Cancelled, $project->fresh()->status);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }
}
