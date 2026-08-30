<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomerProfile;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\TasksIndex;
use App\Livewire\Employee\MyTasks;
use App\Models\Task;
use App\Services\GlobalSearchService;
use App\Support\AdminNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Retirement of the Projects and Quotations modules, and the Employee's new
 * ability to create their own tasks. UI/workflow only — historical tables and
 * models are untouched.
 */
class WorkflowRetirementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return list<string> every route referenced anywhere in the admin sidebar */
    private function navRoutes(): array
    {
        return collect(AdminNavigation::groups())
            ->flatMap(fn ($g) => Arr::pluck($g['items'], 'route'))
            ->filter()->values()->all();
    }

    // ---- Projects removal ----

    public function test_admin_sidebar_has_no_projects_or_quotations(): void
    {
        $routes = $this->navRoutes();
        $this->assertNotContains('admin.projects', $routes);
        $this->assertNotContains('admin.quotations', $routes);
        // Tasks remain the operational unit.
        $this->assertContains('admin.tasks', $routes);
    }

    public function test_project_and_quotation_routes_do_not_exist(): void
    {
        foreach (['admin.projects', 'admin.projects.show', 'admin.quotations', 'admin.quotations.show', 'employee.projects', 'admin.reports.projects'] as $name) {
            $this->assertFalse(app('router')->has($name), "route [{$name}] must not exist");
        }
    }

    public function test_removed_project_route_returns_not_found(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $this->get('/admin/projects')->assertNotFound();
        $this->get('/admin/quotations')->assertNotFound();
    }

    public function test_customer_profile_has_no_projects_or_quotations_tab(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        Livewire::test(CustomerProfile::class, ['customer' => $this->makeCustomer()])
            ->assertOk()
            ->assertDontSee('>المشاريع<', false)
            ->assertDontSee('عروض الأسعار');
    }

    public function test_admin_dashboard_renders_without_project_or_quotation(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        Livewire::test(Dashboard::class)->assertOk()->assertDontSee('مشاريع نشطة');
    }

    public function test_global_search_returns_no_projects_or_quotations(): void
    {
        $this->actingAs($sa = $this->makeUser(RoleName::SuperAdmin));
        $keys = array_column(app(GlobalSearchService::class)->search($sa, 'ا'), 'key');
        $this->assertNotContains('projects', $keys);
        $this->assertNotContains('quotations', $keys);
    }

    public function test_legacy_task_with_project_id_does_not_throw(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        // A task carrying a legacy project_id must still render everywhere.
        $customer = $this->makeCustomer();
        $project = $this->makeProject($customer);
        $task = $this->makeTask(['project_id' => $project->id, 'customer_id' => $customer->id]);

        $this->get(route('admin.tasks'))->assertOk();
        $this->get(route('admin.tasks.show', $task))->assertOk()->assertSee($task->title);
    }

    // ---- Employee task creation ----

    public function test_employee_role_has_tasks_create(): void
    {
        [$user] = $this->makeStaff();
        $this->assertTrue($user->can('tasks.create'));
        // …but never assignment or finance.
        $this->assertFalse($user->can('tasks.assign'));
        $this->assertFalse($user->can('invoices.view'));
    }

    public function test_employee_sees_create_button_and_can_create_self_assigned_task(): void
    {
        [$user, $employee] = $this->makeStaff();

        Livewire::actingAs($user)->test(MyTasks::class)
            ->assertOk()
            ->assertSee('مهمة جديدة')
            ->call('create')
            ->set('title', 'مهمتي الجديدة')
            ->set('task_priority', 'normal')
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'مهمتي الجديدة')->first();
        $this->assertNotNull($task);
        $this->assertSame($employee->id, $task->primary_assignee_id); // self-assigned
    }

    public function test_employee_created_task_appears_in_my_tasks(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->makeTask(['title' => 'قائمة مهامي', 'primary_assignee_id' => $employee->id]);

        Livewire::actingAs($user)->test(MyTasks::class)->assertSee('قائمة مهامي');
    }

    public function test_employee_cannot_assign_task_to_another_employee(): void
    {
        [$user] = $this->makeStaff();

        // The self-service form exposes no assignee field at all — an attempt to
        // set one is rejected by Livewire (property does not exist).
        $component = Livewire::actingAs($user)->test(MyTasks::class)->call('create');
        $this->assertFalse(property_exists($component->instance(), 'primary_assignee_id'));
    }

    public function test_manager_with_assign_permission_can_assign_another_employee(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        [, $other] = $this->makeStaff();
        $this->assertTrue($pm->can('tasks.assign'));

        Livewire::actingAs($pm)->test(TasksIndex::class)
            ->call('create')
            ->set('title', 'مهمة مُسندة')
            ->set('primary_assignee_id', $other->id)
            ->set('task_priority', 'normal')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($other->id, Task::where('title', 'مهمة مُسندة')->first()->primary_assignee_id);
    }

    public function test_employee_with_tasks_create_still_cannot_reach_finance(): void
    {
        [$user] = $this->makeStaff();

        foreach (['/admin/invoices', '/admin/payments', '/admin/accounting/chart', '/admin/expenses'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('employee.dashboard'));
        }
    }
}
