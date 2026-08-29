<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'إجازة سنوية', 'code' => 'ANNUAL', 'is_paid' => true, 'requires_attachment' => false],
            ['name' => 'إجازة مرضية', 'code' => 'SICK', 'is_paid' => true, 'requires_attachment' => true],
            ['name' => 'إجازة طارئة', 'code' => 'EMERGENCY', 'is_paid' => true, 'requires_attachment' => false],
            ['name' => 'إجازة بدون راتب', 'code' => 'UNPAID', 'is_paid' => false, 'requires_attachment' => false],
            ['name' => 'أخرى', 'code' => 'OTHER', 'is_paid' => false, 'requires_attachment' => false],
        ];

        foreach ($types as $i => $type) {
            LeaveType::updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true, 'sort_order' => $i]),
            );
        }
    }
}
