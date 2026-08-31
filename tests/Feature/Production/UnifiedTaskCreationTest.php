<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Livewire\Admin\TasksIndex;
use App\Livewire\Concerns\CreatesTasks;
use App\Livewire\Employee\MyTasks;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Task creation is unified across every role. There is ONE form (fields, order,
 * validation), ONE operational customer/service lookup, ONE ad-budget rule and
 * ONE backend path (TaskService::create) shared by the employee portal
 * (/employee/tasks) and the admin task list (/admin/tasks). Whoever holds
 * tasks.create — Employee, Super Admin, General Manager, or any custom role —
 * gets exactly the same modal and behaviour. No financial data ever leaks
 * through these operational lookups.
 */
class UnifiedTaskCreationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function service(bool $ad = false, string $price = '250.00', ?string $name = null): Service
    {
        $this->seq++;

        return Service::create([
            'service_code' => 'UNI-'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'name' => $name ?? (($ad ? 'اعلانات ممولة ' : 'خدمة ').$this->seq),
            'category' => $ad ? 'إعلانات' : 'تصميم',
            'service_type' => 'custom',
            'requires_ad_budget' => $ad,
            'default_price_usd' => $price,
            'estimated_cost_ils' => '90.00',
            'tax_rate' => '16.00',
            'is_active' => true,
        ]);
    }

    /** A user carrying exactly $permissions through a freshly named custom role. */
    private function userWithRole(string $roleName, array $permissions): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'u'.uniqid(), 'email' => uniqid().'@t.local',
            'password' => Hash::make('x'), 'is_active' => true, 'locale' => 'ar',
        ]);
        $user->assignRole($roleName);

        return User::find($user->id);
    }

    // ---- Same shared form for every role ----

    public function test_employee_admin_and_custom_role_use_the_same_form_class(): void
    {
        // The admin list and the employee portal both compose the same shared
        // create trait — one source of truth, no copy/paste.
        $this->assertContains(
            CreatesTasks::class,
            class_uses_recursive(TasksIndex::class),
        );
        $this->assertContains(
            CreatesTasks::class,
            class_uses_recursive(MyTasks::class),
        );
    }

    public function test_all_three_roles_see_the_new_task_button(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        Livewire::actingAs($emp)->test(MyTasks::class)->assertOk()->assertSee('مهمة جديدة');

        $sa = $this->makeUser(RoleName::SuperAdmin);
        Livewire::actingAs($sa)->test(TasksIndex::class)->assertOk()->assertSee('مهمة جديدة');

        $custom = $this->userWithRole('منسق مهام', ['tasks.view', 'tasks.create']);
        Livewire::actingAs($custom)->test(TasksIndex::class)->assertOk()->assertSee('مهمة جديدة');
    }

    public function test_viewer_without_create_permission_sees_no_button(): void
    {
        $viewer = $this->userWithRole('عارض مهام', ['tasks.view']);
        Livewire::actingAs($viewer)->test(TasksIndex::class)
            ->assertOk()
            ->assertDontSee('مهمة جديدة');
    }

    // ---- Field set: what the form has, and what it must NOT have ----

    public function test_form_exposes_only_the_official_operational_fields(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $component = Livewire::actingAs($emp)->test(MyTasks::class)->call('create')->instance();

        foreach (['title', 'description', 'customer_id', 'selectedServiceIds', 'task_priority', 'start_date', 'due_date', 'ad_budget_amount', 'ad_budget_currency'] as $field) {
            $this->assertTrue(property_exists($component, $field), "form must expose {$field}");
        }
    }

    public function test_form_has_no_legacy_admin_fields(): void
    {
        $sa = $this->makeUser(RoleName::SuperAdmin);
        $component = Livewire::actingAs($sa)->test(TasksIndex::class)->call('create')->instance();

        foreach (['primary_assignee_id', 'department_id', 'estimated_minutes', 'project_id'] as $field) {
            $this->assertFalse(property_exists($component, $field), "form must NOT expose {$field}");
        }
    }

    public function test_dates_default_to_today_and_priority_defaults_to_normal(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        Livewire::actingAs($emp)->test(MyTasks::class)
            ->call('create')
            ->assertSet('start_date', now()->toDateString())
            ->assertSet('due_date', now()->toDateString())
            ->assertSet('task_priority', 'normal')
            ->assertSet('ad_budget_currency', 'ILS');
    }

    // ---- Permission gate is enforced on the backend, not just the UI ----

    public function test_backend_save_authorizes_even_if_button_is_bypassed(): void
    {
        $viewer = $this->userWithRole('عارض فقط', ['tasks.view']);
        $customer = $this->makeCustomer();
        $service = $this->service();

        Livewire::actingAs($viewer)->test(TasksIndex::class)
            ->set('title', 'محاولة غير مصرّحة')
            ->set('customer_id', $customer->id)
            ->set('selectedServiceIds', [$service->id])
            ->set('start_date', now()->toDateString())
            ->set('due_date', now()->toDateString())
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('tasks', ['title' => 'محاولة غير مصرّحة']);
    }

    // ---- Customer operational lookup ----

    public function test_customer_lookup_matches_name_number_and_whatsapp(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $target = $this->makeCustomer(['name' => 'شركة الأفق', 'customer_number' => 'CUS-99001', 'whatsapp_number' => '970599111222']);
        $this->makeCustomer(['name' => 'عميل آخر', 'customer_number' => 'CUS-99002']);

        // by name
        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('customerSearch', 'الأفق')->assertSee('CUS-99001');
        // by customer number
        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('customerSearch', '99001')->assertSee('شركة الأفق');
        // by whatsapp
        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('customerSearch', '599111222')->assertSee('شركة الأفق');

        $this->assertSame($target->id, $target->id); // anchor
    }

    public function test_customer_lookup_never_exposes_financial_data(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer(['name' => 'عميل مالي']);

        // Selecting a customer only loads safe operational columns.
        $component = Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('customerSearch', 'عميل مالي');

        $results = $component->viewData('customerResults');
        $loaded = array_keys($results->first()->getAttributes());
        foreach (['balance', 'outstanding', 'credit_limit'] as $money) {
            $this->assertNotContains($money, $loaded);
        }
        // Only id/name/customer_number/whatsapp_number are ever selected.
        $this->assertEqualsCanonicalizing(['id', 'name', 'customer_number', 'whatsapp_number'], $loaded);
    }

    // ---- Service multi-select ----

    public function test_service_picker_never_loads_financial_columns_even_for_admin(): void
    {
        $sa = $this->makeUser(RoleName::SuperAdmin);
        $this->service();

        $component = Livewire::actingAs($sa)->test(TasksIndex::class)->call('create')
            ->set('serviceSearch', 'خدمة');

        $results = $component->viewData('serviceResults');
        $loaded = array_keys($results->first()->getAttributes());
        foreach (Service::FINANCIAL_FIELDS as $money) {
            $this->assertNotContains($money, $loaded, "service picker leaked {$money}");
        }
    }

    public function test_task_saved_with_multiple_services_syncs_the_pivot(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $a = $this->service();
        $b = $this->service();

        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('title', 'مهمة متعددة الخدمات')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $a->id)
            ->call('toggleService', $b->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'مهمة متعددة الخدمات')->first();
        $this->assertNotNull($task);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $task->services->pluck('id')->all());
    }

    public function test_customer_and_at_least_one_service_are_required(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);

        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('title', 'ناقصة')
            ->call('save')
            ->assertHasErrors(['customer_id', 'selectedServiceIds']);

        $this->assertDatabaseMissing('tasks', ['title' => 'ناقصة']);
    }

    // ---- Ad budget rule (keyed on requires_ad_budget, never on name) ----

    public function test_ad_budget_required_only_when_a_funded_ads_service_is_chosen(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $normal = $this->service();
        $ad = $this->service(ad: true);

        $c = Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->call('toggleService', $normal->id)
            ->assertSet('showForm', true);
        $this->assertFalse($c->instance()->adBudgetRequired());

        $c->call('toggleService', $ad->id);
        $this->assertTrue($c->instance()->adBudgetRequired());
    }

    public function test_removing_last_funded_ads_service_clears_the_budget(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $ad = $this->service(ad: true);

        $c = Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '500')
            ->call('toggleService', $ad->id); // remove it

        $this->assertFalse($c->instance()->adBudgetRequired());
        $c->assertSet('ad_budget_amount', null);
    }

    public function test_ad_budget_amount_must_be_positive_when_required(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);

        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('title', 'حملة بلا ميزانية')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '0')
            ->call('save')
            ->assertHasErrors(['ad_budget_amount']);
    }

    public function test_funded_ads_task_saves_budget_amount_and_currency(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);

        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('title', 'حملة ممولة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '750')
            ->set('ad_budget_currency', 'USD')
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'حملة ممولة')->first();
        $this->assertNotNull($task);
        $this->assertSame('750.00', (string) $task->ad_budget_amount);
        $this->assertSame('USD', $task->ad_budget_currency);
    }

    public function test_non_funded_task_stores_no_ad_budget(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $normal = $this->service();

        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('title', 'مهمة عادية')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $normal->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'مهمة عادية')->first();
        $this->assertNull($task->ad_budget_amount);
        $this->assertNull($task->ad_budget_currency);
    }

    // ---- Dates ----

    public function test_due_date_before_start_date_is_rejected(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $service = $this->service();

        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('title', 'تواريخ خاطئة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $service->id)
            ->set('start_date', now()->addDay()->toDateString())
            ->set('due_date', now()->toDateString())
            ->call('save')
            ->assertHasErrors(['due_date']);
    }

    // ---- Creator becomes member (staff) vs no member (admin without profile) ----

    public function test_staff_creator_becomes_the_tasks_primary_member(): void
    {
        [$user, $employee] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $service = $this->service();

        Livewire::actingAs($user)->test(MyTasks::class)->call('create')
            ->set('title', 'مهمة الموظف')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $service->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'مهمة الموظف')->first();
        $this->assertSame($employee->id, $task->primary_assignee_id);
        $this->assertTrue($task->activeMembers->contains('id', $employee->id));
    }

    public function test_admin_without_employee_profile_creates_a_memberless_task(): void
    {
        // Point 9: a Super Admin may have NO employee record. The task must be
        // created correctly with no initial member — no fake employee, no user
        // mutation.
        $sa = $this->makeUser(RoleName::SuperAdmin);
        $this->assertNull($sa->employee);
        $customer = $this->makeCustomer();
        $service = $this->service();

        Livewire::actingAs($sa)->test(TasksIndex::class)->call('create')
            ->set('title', 'مهمة المدير')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $service->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'مهمة المدير')->first();
        $this->assertNotNull($task);
        $this->assertNull($task->primary_assignee_id);
        $this->assertNull($task->department_id);
        $this->assertTrue($task->activeMembers->isEmpty());
        // The acting user was not turned into an employee.
        $this->assertNull($sa->fresh()->employee_id);
        $this->assertSame(0, Employee::where('user_id', $sa->id)->count());
    }

    public function test_custom_role_without_profile_also_creates_safely(): void
    {
        $custom = $this->userWithRole('مدير حملات', ['tasks.view', 'tasks.create']);
        $customer = $this->makeCustomer();
        $service = $this->service();

        Livewire::actingAs($custom)->test(TasksIndex::class)->call('create')
            ->set('title', 'مهمة الدور المخصص')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $service->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'مهمة الدور المخصص')->first();
        $this->assertNotNull($task);
        $this->assertNull($task->primary_assignee_id);
        $this->assertSame(TaskStatus::New, $task->status);
    }

    // ---- Task lifecycle & cross-portal visibility ----

    public function test_created_task_starts_in_new_status_with_a_number(): void
    {
        [$user] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $service = $this->service();

        Livewire::actingAs($user)->test(MyTasks::class)->call('create')
            ->set('title', 'مهمة جديدة الحالة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $service->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'مهمة جديدة الحالة')->first();
        $this->assertSame(TaskStatus::New, $task->status);
        $this->assertNotEmpty($task->task_number);
    }

    public function test_task_created_by_employee_is_visible_in_admin_list(): void
    {
        [$user] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer(['name' => 'عميل مشترك']);
        $service = $this->service();

        Livewire::actingAs($user)->test(MyTasks::class)->call('create')
            ->set('title', 'مهمة تظهر للإدارة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $service->id)
            ->call('save')
            ->assertHasNoErrors();

        $sa = $this->makeUser(RoleName::SuperAdmin);
        Livewire::actingAs($sa)->test(TasksIndex::class)
            ->assertOk()
            ->assertSee('مهمة تظهر للإدارة');
    }

    public function test_save_closes_the_modal_and_flashes_status(): void
    {
        [$user] = $this->makeStaff(RoleName::Employee);
        $customer = $this->makeCustomer();
        $service = $this->service();

        Livewire::actingAs($user)->test(MyTasks::class)->call('create')
            ->set('title', 'إغلاق النافذة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $service->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSee('تم إنشاء المهمة.'); // success banner rendered

        $this->assertNotNull(Task::where('title', 'إغلاق النافذة')->first());
    }

    // ---- No query explosion on the operational lookups ----

    public function test_service_and_customer_lookups_have_no_query_explosion(): void
    {
        [$emp] = $this->makeStaff(RoleName::Employee);
        for ($i = 0; $i < 15; $i++) {
            $this->service();
            $this->makeCustomer();
        }

        DB::enableQueryLog();
        Livewire::actingAs($emp)->test(MyTasks::class)->call('create')
            ->set('customerSearch', 'عميل')
            ->set('serviceSearch', 'خدمة');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Server-side search with hard limits — not one query per row.
        $this->assertLessThan(40, $count);
    }
}
