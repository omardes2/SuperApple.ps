<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class LeaveRequestSeeder extends Seeder
{
    public function run(): void
    {
        /** @var LeaveService $service */
        $service = app(LeaveService::class);

        $annual = LeaveType::where('code', 'ANNUAL')->first();
        $sick = LeaveType::where('code', 'EMERGENCY')->first();
        $hrUser = User::where('email', 'hr@superapple.ps')->first();

        $designer = Employee::where('employee_number', 'EMP-0006')->first();
        $writer = Employee::where('employee_number', 'EMP-0008')->first();
        $photographer = Employee::where('employee_number', 'EMP-0009')->first();

        if (! $annual || ! $designer) {
            return;
        }

        // Acting user context so created_by / audit are populated sensibly.
        Auth::login($designer->user ?? User::where('email', 'employee@superapple.ps')->first());

        // 1) A pending request (future dates).
        $service->submit(
            $designer, $annual,
            Carbon::today()->addDays(10), Carbon::today()->addDays(12),
            'إجازة عائلية',
        );

        // 2) An approved request in the recent past (syncs attendance to Leave).
        if ($writer) {
            $req = $service->submit(
                $writer, $annual,
                Carbon::today()->subDays(4), Carbon::today()->subDays(3),
                'سفر',
            );
            if ($hrUser) {
                Auth::login($hrUser);
                $service->approve($req, $hrUser, 'موافق');
            }
        }

        // 3) A rejected emergency request.
        if ($photographer && $sick && $hrUser) {
            Auth::login($photographer->user ?? $hrUser);
            $req = $service->submit(
                $photographer, $sick,
                Carbon::today()->addDays(2), Carbon::today()->addDays(2),
                'ظرف طارئ',
            );
            Auth::login($hrUser);
            $service->reject($req, $hrUser, 'يرجى إعادة الجدولة');
        }

        Auth::logout();
    }
}
