<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

trait CreatesUsers
{
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    protected function makeUser(RoleName $role, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => $role->value,
            'email' => str()->random(8).'@test.local',
            'password' => Hash::make('password'),
            'is_active' => true,
            'locale' => 'ar',
        ], $attributes));

        $user->assignRole($role->value);

        return $user;
    }
}
