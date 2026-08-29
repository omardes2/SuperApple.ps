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
            // Sprint 5 — accounting foundation (chart of accounts must exist
            // before any invoice/payment/expense journal is posted).
            ChartOfAccountsSeeder::class,
            AccountingSetupSeeder::class,
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
            // Sprint 4 — payments & collection
            PaymentSeeder::class,
            // Sprint 5 — suppliers, expenses, bills, supplier payments
            Sprint5DemoSeeder::class,
        ]);
    }
}
