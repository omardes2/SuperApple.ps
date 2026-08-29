<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'gm@superapple.ps')->first() ?? User::first());
        $service = app(ProjectService::class);

        $customers = Customer::orderBy('id')->take(6)->get();
        $pm = Employee::where('employee_number', 'EMP-0004')->first();
        $designers = Employee::whereIn('employee_number', ['EMP-0005', 'EMP-0006', 'EMP-0007'])->get();
        $others = Employee::whereIn('employee_number', ['EMP-0008', 'EMP-0009', 'EMP-0010'])->get();

        $blueprint = [
            ['هوية بصرية متكاملة', 'active', 'high'],
            ['حملة سوشيال ميديا', 'active', 'normal'],
            ['موقع إلكتروني', 'under_review', 'high'],
            ['متجر إلكتروني', 'active', 'urgent'],
            ['فيديو ترويجي', 'on_hold', 'normal'],
            ['هوية + طباعة', 'draft', 'low'],
        ];

        foreach ($blueprint as $i => [$name, $status, $priority]) {
            $customer = $customers[$i % $customers->count()];
            $number = 'PRJ-'.now()->year.'-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            if (Project::where('project_number', $number)->exists()) {
                continue;
            }

            $project = $service->create([
                'project_number' => $number,
                'customer_id' => $customer->id,
                'name' => $name.' - '.$customer->name,
                'description' => 'مشروع '.$name.' للعميل '.$customer->name.'.',
                'project_manager_id' => $pm?->id,
                'department_id' => $pm?->department_id,
                'priority' => $priority,
                'status' => $status,
                'start_date' => now()->subDays(random_int(5, 30))->toDateString(),
                'due_date' => now()->addDays(random_int(5, 40))->toDateString(),
            ]);

            // Members: a couple of designers + one other.
            foreach ($designers->random(min(2, $designers->count())) as $emp) {
                $service->addMember($project, $emp, 'مصمم');
            }
            if ($others->isNotEmpty()) {
                $service->addMember($project, $others->random(), 'تنفيذ');
            }
        }

        Auth::logout();
    }
}
