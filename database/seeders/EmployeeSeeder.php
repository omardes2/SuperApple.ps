<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $dept = fn (string $code) => Department::where('code', $code)->value('id');
        $user = fn (string $email) => User::where('email', $email)->first();

        // number => [full_name, dept code, email|null, job title, manager number|null, type]
        $rows = [
            'EMP-0001' => ['المدير العام', 'MGMT', 'gm@superapple.ps', 'المدير العام', null, 'full_time'],
            'EMP-0002' => ['مدير الموارد البشرية', 'MGMT', 'hr@superapple.ps', 'مدير موارد بشرية', 'EMP-0001', 'full_time'],
            'EMP-0003' => ['المحاسب', 'ACCT', 'accountant@superapple.ps', 'محاسب', 'EMP-0001', 'full_time'],
            'EMP-0004' => ['مدير المشاريع', 'MGMT', 'pm@superapple.ps', 'مدير مشاريع', 'EMP-0001', 'full_time'],
            'EMP-0005' => ['قائد فريق التصميم', 'DSGN', 'lead@superapple.ps', 'قائد فريق', 'EMP-0004', 'full_time'],
            'EMP-0006' => ['موظف تصميم', 'DSGN', 'employee@superapple.ps', 'مصمم', 'EMP-0005', 'full_time'],
            'EMP-0007' => ['سارة الجرافيك', 'DSGN', null, 'مصممة جرافيك', 'EMP-0005', 'full_time'],
            'EMP-0008' => ['محمد المحتوى', 'MKTG', null, 'كاتب محتوى', 'EMP-0004', 'part_time'],
            'EMP-0009' => ['خالد المصور', 'PROD', null, 'مصوّر ومنتج', 'EMP-0004', 'contract'],
            'EMP-0010' => ['ليان المطوّرة', 'DEV', null, 'مطوّرة ويب', 'EMP-0004', 'freelance'],
        ];

        // Pass 1: create/update employees (without managers yet).
        foreach ($rows as $number => [$name, $code, $email, $title, , $type]) {
            $u = $email ? $user($email) : null;

            Employee::updateOrCreate(
                ['employee_number' => $number],
                [
                    'full_name' => $name,
                    'user_id' => $u?->id,
                    'department_id' => $dept($code),
                    'job_title' => $title,
                    'hire_date' => now()->subMonths(random_int(3, 30))->toDateString(),
                    'employment_status' => 'active',
                    'employment_type' => $type,
                    'working_hours_per_day' => 8,
                    'phone' => $u?->phone,
                    'is_active' => true,
                ],
            );
        }

        // Pass 2: wire managers + link users back to their employee profile.
        foreach ($rows as $number => [, , $email, , $managerNumber]) {
            $employee = Employee::where('employee_number', $number)->first();

            if ($managerNumber) {
                $employee->update([
                    'direct_manager_id' => Employee::where('employee_number', $managerNumber)->value('id'),
                ]);
            }

            if ($email && ($u = $user($email))) {
                $u->update(['employee_id' => $employee->id]);
            }
        }

        // A couple of department managers.
        Department::where('code', 'DSGN')->update(['manager_id' => Employee::where('employee_number', 'EMP-0005')->value('id')]);
        Department::where('code', 'ACCT')->update(['manager_id' => Employee::where('employee_number', 'EMP-0003')->value('id')]);
    }
}
