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
        $task = $this->makeTask(['customer_id' => $customer->id]);

        foreach (['/admin', '/admin/customers', '/admin/services', '/admin/tasks'] as $url) {
            $this->actingAs($gm)->get($url)->assertOk();
        }
        $this->actingAs($gm)->get(route('admin.customers.show', $customer))->assertOk()->assertSee($customer->name);
        $this->actingAs($gm)->get(route('admin.tasks.show', $task))->assertOk()->assertSee($task->title);
    }

    public function test_employee_operational_pages_render(): void
    {
        [$user, $employee] = $this->makeStaff();
        $task = $this->makeTask(['primary_assignee_id' => $employee->id]);

        foreach (['/employee', '/employee/tasks'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->actingAs($user)->get(route('employee.tasks.show', $task))->assertOk()->assertSee($task->title);
    }
}
