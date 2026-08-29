<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'مدير النظام', 'email' => 'admin@superapple.ps', 'phone' => '0590000001', 'role' => RoleName::SuperAdmin],
            ['name' => 'المدير العام', 'email' => 'gm@superapple.ps', 'phone' => '0590000002', 'role' => RoleName::GeneralManager],
            ['name' => 'المحاسب', 'email' => 'accountant@superapple.ps', 'phone' => '0590000003', 'role' => RoleName::Accountant],
            ['name' => 'مدير الموارد البشرية', 'email' => 'hr@superapple.ps', 'phone' => '0590000004', 'role' => RoleName::HrManager],
            ['name' => 'مدير المشاريع', 'email' => 'pm@superapple.ps', 'phone' => '0590000005', 'role' => RoleName::ProjectManager],
            ['name' => 'قائد الفريق', 'email' => 'lead@superapple.ps', 'phone' => '0590000006', 'role' => RoleName::TeamLeader],
            ['name' => 'موظف تصميم', 'email' => 'employee@superapple.ps', 'phone' => '0590000007', 'role' => RoleName::Employee],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'locale' => 'ar',
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$data['role']->value]);
        }
    }
}
