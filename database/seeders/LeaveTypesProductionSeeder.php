<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * The core leave types every company needs, safe to run against production.
 * Idempotent (updateOrCreate by code) — re-running never duplicates rows and
 * never deactivates a type an admin has since disabled by hand… it only ensures
 * the base set exists. This is NOT demo data.
 *
 *   php artisan db:seed --class=LeaveTypesProductionSeeder --force
 */
class LeaveTypesProductionSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'annual', 'name' => 'إجازة سنوية', 'is_paid' => true, 'requires_attachment' => false],
            ['code' => 'sick', 'name' => 'إجازة مرضية', 'is_paid' => true, 'requires_attachment' => false],
            ['code' => 'unpaid', 'name' => 'إجازة بدون راتب', 'is_paid' => false, 'requires_attachment' => false],
            ['code' => 'emergency', 'name' => 'إجازة طارئة', 'is_paid' => true, 'requires_attachment' => false],
        ];

        foreach ($types as $i => $type) {
            LeaveType::updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'is_paid' => $type['is_paid'],
                    'requires_attachment' => $type['requires_attachment'],
                    'is_active' => true,
                    'sort_order' => $i,
                ],
            );
        }
    }
}
