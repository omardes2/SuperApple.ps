<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\RolesPermissions;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\PermissionDependencies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Admin permission dependencies: selecting any operational permission in a
 * module auto-carries that module's view permission, so a custom role can never
 * end up able to create/assign tasks yet unable to see the Tasks module. The
 * employee-context tasks.view_own must never imply the admin tasks.view.
 */
class PermissionDependenciesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function roleWith(string $name, array $perms): Role
    {
        $role = Role::findOrCreate($name, 'web');
        $role->syncPermissions($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    private function userWithRole(Role $role): User
    {
        $user = User::create([
            'name' => 'u'.uniqid(), 'email' => uniqid().'@t.local',
            'password' => Hash::make('x'), 'is_active' => true, 'locale' => 'ar',
        ]);
        $user->assignRole($role->name);

        return User::find($user->id);
    }

    private function navLabels(User $user): array
    {
        $labels = [];
        foreach (AdminNavigation::groups() as $group) {
            foreach ($group['items'] as $item) {
                if ($user->can($item['permission'])) {
                    $labels[] = $item['label'];
                }
            }
        }

        return $labels;
    }

    /** Save a role's permissions through the actual editor component. */
    private function saveViaEditor(Role $role, array $permissions): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        Livewire::test(RolesPermissions::class)
            ->call('selectRole', $role->id)
            ->set('rolePermissions', $permissions)
            ->call('saveRolePermissions')
            ->assertHasNoErrors();
    }

    // ---- Unit: the dependency map ----

    public function test_expand_adds_module_view_for_operations(): void
    {
        foreach (['tasks.create', 'tasks.assign', 'tasks.review', 'tasks.edit', 'tasks.manage'] as $op) {
            $this->assertContains('tasks.view', PermissionDependencies::expand([$op]), "{$op} must require tasks.view");
        }
        $this->assertContains('invoices.view', PermissionDependencies::expand(['invoices.create']));
        $this->assertContains('customers.view', PermissionDependencies::expand(['customers.edit']));
        $this->assertContains('expenses.view', PermissionDependencies::expand(['expenses.post']));
        $this->assertContains('payments.view', PermissionDependencies::expand(['payments.allocate']));
    }

    public function test_view_own_never_implies_admin_view(): void
    {
        $this->assertNotContains('tasks.view', PermissionDependencies::expand(['tasks.view_own']));
        $this->assertNotContains('attendance.view', PermissionDependencies::expand(['attendance.view_own']));
        $this->assertNotContains('leaves.view', PermissionDependencies::expand(['leaves.view_own']));
    }

    // ---- Editor behaviour ----

    public function test_selecting_task_operations_persists_tasks_view(): void
    {
        foreach (['tasks.create', 'tasks.assign', 'tasks.review'] as $op) {
            $role = Role::findOrCreate('role-'.$op, 'web');
            $this->saveViaEditor($role, [$op, 'tasks.view_own']);

            $names = $role->fresh()->permissions->pluck('name')->all();
            $this->assertContains('tasks.view', $names, "{$op} save must add tasks.view");
            $this->assertContains($op, $names);
            $this->assertContains('tasks.view_own', $names); // employee perm preserved
        }
    }

    public function test_reproduces_and_fixes_production_role(): void
    {
        // Exactly the production role: task operations + view_own, no tasks.view.
        $role = Role::findOrCreate('دور الإنتاج', 'web');
        $this->saveViaEditor($role, [
            'tasks.assign', 'tasks.attachments', 'tasks.checklist', 'tasks.comment',
            'tasks.create', 'tasks.review', 'tasks.view_own',
        ]);

        $user = $this->userWithRole($role->fresh());
        $this->assertTrue($user->can('tasks.view'));
        $this->assertContains('المهام', $this->navLabels($user));

        $this->actingAs($user);
        $this->get(route('admin.tasks'))->assertOk();
    }

    public function test_editor_can_remove_tasks_view_when_deselected_with_no_dependents(): void
    {
        $role = $this->roleWith('محرر', ['tasks.view', 'tasks.create']);
        $this->assertContains('tasks.view', $role->fresh()->permissions->pluck('name')->all());

        // Deselect everything → nothing left to depend on tasks.view.
        $this->saveViaEditor($role, []);
        $this->assertEmpty($role->fresh()->permissions);
    }

    public function test_deselecting_view_but_keeping_operation_readds_view(): void
    {
        $role = $this->roleWith('مشرف', ['tasks.view', 'tasks.create']);
        // Uncheck tasks.view but keep tasks.create → dependency re-adds view.
        $this->saveViaEditor($role, ['tasks.create']);

        $this->assertContains('tasks.view', $role->fresh()->permissions->pluck('name')->all());
    }

    // ---- Navigation & access ----

    public function test_tasks_view_shows_menu_and_opens_index(): void
    {
        $user = $this->userWithRole($this->roleWith('عارض', ['dashboard.view', 'tasks.view']));
        $this->assertContains('المهام', $this->navLabels($user));

        $this->actingAs($user);
        $this->get(route('admin.tasks'))->assertOk();
    }

    public function test_only_view_own_does_not_show_admin_tasks_or_open_route(): void
    {
        $user = $this->userWithRole($this->roleWith('موظف مخصص', ['tasks.view_own', 'tasks.create']));

        $this->assertNotContains('المهام', $this->navLabels($user));
        $this->assertFalse($user->usesAdminExperience());

        $this->actingAs($user);
        $this->get(route('admin.tasks'))->assertRedirect(route('employee.dashboard'));
    }

    public function test_custom_role_shows_all_three_modules_from_admin_operations(): void
    {
        $role = Role::findOrCreate('مدير تسويق', 'web');
        // Only operations selected — views come from the dependency expansion.
        $this->saveViaEditor($role, ['customers.create', 'invoices.create', 'tasks.assign']);

        $user = $this->userWithRole($role->fresh());
        $labels = $this->navLabels($user);
        $this->assertContains('العملاء', $labels);
        $this->assertContains('الفواتير', $labels);
        $this->assertContains('المهام', $labels);
    }

    public function test_cache_correct_after_expanded_save(): void
    {
        $role = Role::findOrCreate('مباشر', 'web');
        $member = $this->userWithRole($role);
        $this->assertFalse(User::find($member->id)->can('tasks.view'));

        $this->saveViaEditor($role, ['tasks.create']);

        $this->assertTrue(User::find($member->id)->can('tasks.view'));
    }

    // ---- Existing roles unaffected ----

    public function test_employee_view_own_behaviour_unchanged(): void
    {
        [$user] = $this->makeStaff(RoleName::Employee);
        $this->assertTrue($user->can('tasks.view_own'));
        $this->assertFalse($user->can('tasks.view'));
        $this->assertFalse($user->usesAdminExperience());
    }

    public function test_accountant_still_reaches_admin(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->assertTrue($accountant->can('invoices.view'));
        $this->assertContains('الفواتير', $this->navLabels($accountant));
    }
}
