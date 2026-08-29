<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            UserSeeder::class,
            // Sprint 1 — HR
            DepartmentSeeder::class,
            LeaveTypeSeeder::class,
            EmployeeSeeder::class,
            AttendanceSeeder::class,
            LeaveRequestSeeder::class,
            // Sprint 2 — operational core
            CustomerSeeder::class,
            ServiceSeeder::class,
            ProjectSeeder::class,
            TaskSeeder::class,
            // Sprint 3 — billing
            BillingSeeder::class,
        ]);
    }
}
