<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\RolesPermissions;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * RBAC is fully permission-driven: any custom role (any name) with any set of
 * catalog permissions produces the matching navigation and access, the role
 * editor never silently drops permissions, and the cache invalidates so changes
 * take effect immediately. Existing roles and the employee portal are unchanged.
 */
class RbacCustomRolesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles(); // seeds the full permission catalog + default roles
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

    /** Visible admin nav labels for a user (permission-filtered like the layout). */
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

    // ---- Custom role creation & saving through the UI ----

    public function test_custom_role_created_and_permissions_saved_via_ui(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));

        Livewire::test(RolesPermissions::class)
            ->set('newRoleName', 'مدير تسويق')
            ->call('createRole');

        $role = Role::findByName('مدير تسويق', 'web');
        $this->assertNotNull($role);

        Livewire::test(RolesPermissions::class)
            ->call('selectRole', $role->id)
            ->set('rolePermissions', ['customers.view', 'invoices.view', 'tasks.view'])
            ->call('saveRolePermissions')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['customers.view', 'invoices.view', 'tasks.view'],
            $role->fresh()->permissions->pluck('name')->all()
        );
    }

    public function test_user_inherits_all_three_module_permissions(): void
    {
        $user = $this->userWithRole('مدير تسويق', ['customers.view', 'invoices.view', 'tasks.view']);

        $this->assertTrue($user->can('customers.view'));
        $this->assertTrue($user->can('invoices.view'));
        $this->assertTrue($user->can('tasks.view'));
    }

    public function test_all_three_menus_appear_and_others_stay_hidden(): void
    {
        $user = $this->userWithRole('مسؤول عملاء', ['customers.view', 'invoices.view', 'tasks.view']);
        $labels = $this->navLabels($user);

        $this->assertContains('العملاء', $labels);
        $this->assertContains('الفواتير', $labels);
        $this->assertContains('المهام', $labels);
        // Not granted → hidden.
        $this->assertNotContains('المصاريف', $labels);
        $this->assertNotContains('الرواتب', $labels);
    }

    public function test_custom_role_name_does_not_affect_menu(): void
    {
        $a = $this->userWithRole('اسم غريب جداً 123', ['tasks.view']);
        $b = $this->userWithRole('another-random-name', ['tasks.view']);

        $this->assertContains('المهام', $this->navLabels($a));
        $this->assertContains('المهام', $this->navLabels($b));
    }

    public function test_section_heading_hides_when_empty(): void
    {
        // Only operational permissions → the finance section must not render.
        $user = $this->userWithRole('تشغيلي', ['dashboard.view', 'tasks.view']);
        $this->actingAs($user);

        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('المهام', $html);
        $this->assertStringNotContainsString('المالية والمحاسبة', $html);
    }

    // ---- The exact production bug: cache + missing rows ----

    public function test_updating_role_permissions_takes_effect_immediately(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $role = Role::findOrCreate('مشرف', 'web');
        $role->syncPermissions(['customers.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $member = User::create([
            'name' => 'm', 'email' => 'm@t.local', 'password' => Hash::make('x'),
            'is_active' => true, 'locale' => 'ar',
        ]);
        $member->assignRole('مشرف');
        $this->assertFalse(User::find($member->id)->can('tasks.view'));

        // Grant tasks.view through the editor.
        Livewire::test(RolesPermissions::class)
            ->call('selectRole', $role->id)
            ->set('rolePermissions', ['customers.view', 'tasks.view'])
            ->call('saveRolePermissions')
            ->assertHasNoErrors();

        // Effective immediately after the official cache invalidation.
        $this->assertTrue(User::find($member->id)->can('tasks.view'));
    }

    public function test_role_editor_self_heals_a_missing_catalog_permission(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $role = Role::findOrCreate('مدير', 'web');

        // Simulate a production DB whose catalog drifted: the row is gone.
        Permission::where('name', 'tasks.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The save must NOT throw and must persist tasks.view (row re-created).
        Livewire::test(RolesPermissions::class)
            ->call('selectRole', $role->id)
            ->set('rolePermissions', ['customers.view', 'tasks.view'])
            ->call('saveRolePermissions')
            ->assertHasNoErrors();

        $this->assertTrue(Permission::where('name', 'tasks.view')->exists());
        $this->assertContains('tasks.view', $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_permission_sync_recreates_missing_rows_idempotently(): void
    {
        Permission::where('name', 'tasks.view')->delete();
        $this->assertContains('tasks.view', Permissions::missing());

        $created = Permissions::sync();
        $this->assertGreaterThanOrEqual(1, $created);
        $this->assertSame([], Permissions::missing());
        // Idempotent second run creates nothing.
        $this->assertSame(0, Permissions::sync());
    }

    public function test_removing_permission_hides_menu_and_blocks_route(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $role = Role::findOrCreate('محرر', 'web');
        $role->syncPermissions(['customers.view', 'tasks.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'e', 'email' => 'e@t.local', 'password' => Hash::make('x'),
            'is_active' => true, 'locale' => 'ar',
        ]);
        $user->assignRole('محرر');
        $this->assertContains('المهام', $this->navLabels(User::find($user->id)));

        // Remove tasks.view via the editor.
        Livewire::test(RolesPermissions::class)
            ->call('selectRole', $role->id)
            ->set('rolePermissions', ['customers.view'])
            ->call('saveRolePermissions');

        $fresh = User::find($user->id);
        $this->assertNotContains('المهام', $this->navLabels($fresh));

        $this->actingAs($fresh);
        $this->get(route('admin.tasks'))->assertForbidden();
    }

    // ---- Access & granularity ----

    public function test_tasks_view_opens_tasks_index(): void
    {
        $user = $this->userWithRole('عارض مهام', ['tasks.view']);
        $this->actingAs($user);
        $this->get(route('admin.tasks'))->assertOk();
    }

    public function test_view_permission_is_granular(): void
    {
        $user = $this->userWithRole('عارض', ['tasks.view', 'invoices.view', 'customers.view']);

        $this->assertTrue($user->can('tasks.view'));
        $this->assertFalse($user->can('tasks.create'));
        $this->assertFalse($user->can('tasks.assign'));
        $this->assertFalse($user->can('invoices.cancel'));
        $this->assertFalse($user->can('customers.edit'));
    }

    public function test_manual_url_is_protected(): void
    {
        $user = $this->userWithRole('عارض مهام فقط', ['tasks.view']);
        $this->actingAs($user);
        // No invoices.view → the invoices route is forbidden even by direct URL.
        $this->get(route('admin.invoices'))->assertForbidden();
    }

    public function test_direct_user_permission_is_effective(): void
    {
        $user = $this->userWithRole('بلا صلاحيات', []);
        $this->assertFalse($user->can('tasks.view'));

        $user->givePermissionTo('tasks.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(User::find($user->id)->can('tasks.view'));
        $this->assertContains('المهام', $this->navLabels(User::find($user->id)));
    }

    // ---- Admin experience routing is permission-driven ----

    public function test_tasks_only_custom_role_reaches_admin_area(): void
    {
        $user = $this->userWithRole('منسق مهام', ['tasks.view']);
        $this->assertTrue($user->usesAdminExperience());
        $this->actingAs($user);
        $this->get('/admin/tasks')->assertOk();
    }

    // ---- Existing roles & portals unaffected ----

    public function test_superadmin_sees_everything(): void
    {
        $sa = $this->makeUser(RoleName::SuperAdmin);
        foreach (['customers.view', 'invoices.view', 'tasks.view', 'payroll.view', 'accounting.manage'] as $p) {
            $this->assertTrue($sa->can($p));
        }
    }

    public function test_existing_roles_unaffected(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->assertTrue($accountant->can('invoices.view'));
        $this->assertTrue($accountant->usesAdminExperience());

        $hr = $this->makeUser(RoleName::HrManager);
        $this->assertTrue($hr->usesAdminExperience());
    }

    public function test_employee_portal_unaffected(): void
    {
        [$user] = $this->makeStaff(RoleName::Employee);
        $this->assertFalse($user->usesAdminExperience());
        $this->actingAs($user);
        // An employee hitting an admin URL is redirected to their portal.
        $this->get('/admin/tasks')->assertRedirect(route('employee.dashboard'));
    }

    public function test_navigation_filtering_has_no_query_explosion(): void
    {
        $user = $this->userWithRole('perf', ['customers.view', 'invoices.view', 'tasks.view']);
        $this->actingAs($user);

        DB::enableQueryLog();
        $this->navLabels(User::find($user->id));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Permissions load once (cached), not once per nav item.
        $this->assertLessThan(10, $count);
    }
}
