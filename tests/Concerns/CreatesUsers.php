<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
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

    protected function makeCustomer(array $attributes = []): Customer
    {
        static $seq = 0;
        $seq++;

        return Customer::create(array_merge([
            'customer_number' => 'CUS-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'name' => 'عميل تجريبي '.$seq,
            'phone' => '0591000000',
            'status' => 'active',
            'is_active' => true,
        ], $attributes));
    }

    protected function makeProject(?Customer $customer = null, array $attributes = []): Project
    {
        static $seq = 0;
        $seq++;
        $customer ??= $this->makeCustomer();

        return Project::create(array_merge([
            'project_number' => 'PRJ-'.now()->year.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'name' => 'مشروع تجريبي '.$seq,
            'priority' => 'normal',
            'status' => 'active',
        ], $attributes));
    }

    protected function makeTask(array $attributes = []): Task
    {
        static $seq = 0;
        $seq++;

        return Task::create(array_merge([
            'task_number' => 'TSK-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'title' => 'مهمة تجريبية '.$seq,
            'priority' => 'normal',
            'status' => 'new',
        ], $attributes));
    }
}
