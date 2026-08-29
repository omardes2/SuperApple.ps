<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
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

    protected function makeDepartment(array $attributes = []): Department
    {
        return Department::create(array_merge([
            'name' => 'قسم تجريبي',
            'code' => 'D'.str()->upper(str()->random(4)),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Create an employee profile, optionally linked to a user (which also sets
     * the reverse users.employee_id link).
     */
    protected function makeEmployee(?User $user = null, array $attributes = []): Employee
    {
        $employee = Employee::create(array_merge([
            'employee_number' => 'E'.str()->upper(str()->random(6)),
            'full_name' => 'موظف تجريبي',
            'user_id' => $user?->id,
            'employment_status' => 'active',
            'employment_type' => 'full_time',
            'working_hours_per_day' => 8,
            'is_active' => true,
        ], $attributes));

        $user?->update(['employee_id' => $employee->id]);

        return $employee;
    }

    /**
     * A logged-in employee: a user with the given role plus a linked profile.
     *
     * @return array{0: User, 1: Employee}
     */
    protected function makeStaff(RoleName $role = RoleName::Employee, array $employeeAttributes = []): array
    {
        $user = $this->makeUser($role);
        $employee = $this->makeEmployee($user, $employeeAttributes);

        return [$user, $employee];
    }
}
