<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'الإدارة', 'code' => 'MGMT'],
            ['name' => 'التصميم', 'code' => 'DSGN'],
            ['name' => 'التسويق', 'code' => 'MKTG'],
            ['name' => 'السوشيال ميديا', 'code' => 'SOCL'],
            ['name' => 'المبيعات', 'code' => 'SALE'],
            ['name' => 'البرمجة', 'code' => 'DEV'],
            ['name' => 'التصوير والإنتاج', 'code' => 'PROD'],
            ['name' => 'المحاسبة', 'code' => 'ACCT'],
        ];

        foreach ($departments as $i => $dept) {
            Department::updateOrCreate(
                ['code' => $dept['code']],
                ['name' => $dept['name'], 'is_active' => true, 'sort_order' => $i],
            );
        }
    }
}
