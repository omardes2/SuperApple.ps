<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1) Permissions
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // 2) Roles
        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        // 3) Assign default permission bundles
        foreach (Permissions::roleDefaults() as $roleName => $permissions) {
            Role::findByName($roleName, 'web')->syncPermissions($permissions);
        }

        // Super Admin gets everything explicitly too (belt & suspenders alongside Gate::before)
        Role::findByName(RoleName::SuperAdmin->value, 'web')
            ->syncPermissions(Permissions::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
