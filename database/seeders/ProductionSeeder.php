<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The ONLY seeder to run against a production database. It seeds foundational
 * reference data — roles, permissions, the chart of accounts (with its system
 * account mappings) and default settings — and NOTHING else: no demo customers,
 * employees, invoices, payments, or weak-password demo login accounts.
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 *
 * Create the first administrator afterwards with:
 *   php artisan app:create-admin
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,        // roles + permissions
            SettingsSeeder::class,              // default settings (incl. currency rules, version)
            ChartOfAccountsSeeder::class,       // chart of accounts + system account mappings
            LeaveTypesProductionSeeder::class,  // core leave types (annual/sick/unpaid/emergency)
        ]);

        $this->command?->info('تم تجهيز البيانات الأساسية للإنتاج (أدوار/صلاحيات/دليل حسابات/إعدادات). أنشئ المدير بـ: php artisan app:create-admin');
    }
}
