<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Foundational seeders (roles/permissions, settings, chart of accounts) are
     * always safe to run. DEMO seeders produce illustrative data and must NEVER
     * run against a production database by accident — in production they only
     * run when APP_ALLOW_DEMO_SEED=true is set explicitly.
     */
    public function run(): void
    {
        // 1) Foundational — safe in every environment (no login accounts here;
        // production creates its first admin explicitly — see docs/DEPLOYMENT.md).
        $this->call([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);

        // 2) Demo data — guarded in production.
        if (App::environment('production') && ! env('APP_ALLOW_DEMO_SEED', false)) {
            $this->command?->warn('تخطّي بيانات العرض التجريبية في بيئة الإنتاج (اضبط APP_ALLOW_DEMO_SEED=true للسماح بها).');

            return;
        }

        $this->call([
            // Demo login accounts (weak passwords — DEVELOPMENT ONLY).
            UserSeeder::class,
            // Demo opening balances + cash/bank accounts (illustrative figures).
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
            // Sprint 6 — payroll, salaries, advances
            PayrollDemoSeeder::class,
            // Sprint 7 — WhatsApp templates + reminder rules, then subscriptions.
            WhatsAppSeeder::class,
            SubscriptionDemoSeeder::class,
        ]);
    }
}
