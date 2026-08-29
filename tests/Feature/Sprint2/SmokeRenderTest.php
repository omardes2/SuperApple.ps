<?php

namespace Tests\Feature\Sprint2;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SmokeRenderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_admin_operational_pages_render(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $customer = $this->makeCustomer();
        $project = $this->makeProject($customer);
        $task = $this->makeTask(['project_id' => $project->id, 'customer_id' => $customer->id]);

        foreach (['/admin', '/admin/customers', '/admin/services', '/admin/projects', '/admin/tasks'] as $url) {
            $this->actingAs($gm)->get($url)->assertOk();
        }
        $this->actingAs($gm)->get(route('admin.customers.show', $customer))->assertOk()->assertSee($customer->name);
        $this->actingAs($gm)->get(route('admin.projects.show', $project))->assertOk()->assertSee($project->name);
        $this->actingAs($gm)->get(route('admin.tasks.show', $task))->assertOk()->assertSee($task->title);
    }

    public function test_employee_operational_pages_render(): void
    {
        [$user, $employee] = $this->makeStaff();
        $project = $this->makeProject();
        $project->memberships()->create(['employee_id' => $employee->id, 'joined_at' => now()]);
        $task = $this->makeTask(['primary_assignee_id' => $employee->id]);

        foreach (['/employee', '/employee/tasks', '/employee/projects'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->actingAs($user)->get(route('employee.tasks.show', $task))->assertOk()->assertSee($task->title);
        $this->actingAs($user)->get(route('employee.projects.show', $project))->assertOk()->assertSee($project->name);
    }
}
