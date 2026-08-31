<?php

namespace App\Livewire\Admin;

use App\Enums\RoleName;
use App\Support\PermissionDependencies;
use App\Support\Permissions;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

#[Layout('layouts.app')]
#[Title('الأدوار والصلاحيات')]
class RolesPermissions extends Component
{
    /** Financially sensitive permissions that warrant a UI warning when granted. */
    public const DANGEROUS = [
        'accounting.manage', 'payroll.manage', 'payments.post', 'invoices.cancel',
        'journals.manual', 'journals.reverse', 'payments.cancel', 'payroll.post',
        'roles.manage', 'users.manage', 'settings.manage',
    ];

    public ?int $selectedRoleId = null;

    /** @var list<string> */
    public array $rolePermissions = [];

    public string $newRoleName = '';

    public function mount(): void
    {
        $this->authorize('roles.manage');
    }

    public function selectRole(int $id): void
    {
        $role = Role::findById($id);
        $this->selectedRoleId = $role->id;
        $this->rolePermissions = $role->permissions->pluck('name')->all();
    }

    public function createRole(): void
    {
        $this->authorize('roles.manage');
        $this->validate(['newRoleName' => 'required|string|max:60|unique:roles,name']);
        Role::findOrCreate($this->newRoleName, 'web');
        $this->newRoleName = '';
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        session()->flash('status', 'تم إنشاء الدور.');
    }

    public function saveRolePermissions(): void
    {
        $this->authorize('roles.manage');
        if ($this->selectedRoleId === null) {
            return;
        }
        $role = Role::findById($this->selectedRoleId);
        // Super Admin always keeps every permission — never editable here.
        if ($role->name === RoleName::SuperAdmin->value) {
            $this->addError('role', 'دور المدير الأعلى يملك كل الصلاحيات ولا يُعدّل.');

            return;
        }
        // Only ever grant real catalog permissions, and make sure each exists as
        // a row first — otherwise Spatie throws PermissionDoesNotExist and the
        // whole save is lost (the root cause of "custom role lost some modules").
        $selected = array_values(array_intersect($this->rolePermissions, Permissions::all()));

        // Expand through the dependency map: any admin operation carries its
        // module's view permission (e.g. tasks.create ⇒ tasks.view) so the
        // module can never be granted without being reachable in the sidebar.
        $selected = array_values(array_intersect(PermissionDependencies::expand($selected), Permissions::all()));

        foreach ($selected as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $role->syncPermissions($selected);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Reflect the auto-added prerequisites back into the checkboxes.
        $this->rolePermissions = $selected;
        session()->flash('status', 'تم حفظ صلاحيات الدور.');
    }

    /** @return array<string,array<string,int>> matrix[roleName][groupKey] = grantedCount */
    private function matrix(array $roles, array $catalog): array
    {
        $matrix = [];
        foreach ($roles as $role) {
            $granted = $role->permissions->pluck('name')->flip();
            foreach ($catalog as $groupKey => $group) {
                $count = 0;
                foreach (array_keys($group['permissions']) as $perm) {
                    if ($role->name === RoleName::SuperAdmin->value || $granted->has($perm)) {
                        $count++;
                    }
                }
                $matrix[$role->name][$groupKey] = $count;
            }
        }

        return $matrix;
    }

    public function render()
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        $catalog = Permissions::catalog();

        return view('livewire.admin.roles-permissions', [
            'roles' => $roles,
            'catalog' => $catalog,
            'matrix' => $this->matrix($roles->all(), $catalog),
            'dangerous' => self::DANGEROUS,
            'totalPermissions' => Permission::count(),
        ]);
    }
}
