<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        /** @var AttendanceService $service */
        $service = app(AttendanceService::class);

        $employees = Employee::active()->get();

        // Last 14 calendar days; only working days get records.
        for ($i = 1; $i <= 14; $i++) {
            $date = Carbon::today()->subDays($i);

            if (! $service->isWorkingDay($date)) {
                continue;
            }

            foreach ($employees as $employee) {
                // ~10% absent.
                if (random_int(1, 10) === 1) {
                    AttendanceRecord::updateOrCreate(
                        ['employee_id' => $employee->id, 'attendance_date' => $date->toDateString()],
                        ['status' => AttendanceStatus::Absent],
                    );

                    continue;
                }

                // Check-in between 08:50 and 09:30.
                $checkIn = $date->copy()->setTime(9, 0)->addMinutes(random_int(-10, 30));
                $late = $service->lateMinutesFor($checkIn);
                $checkOut = $checkIn->copy()->addMinutes(random_int(470, 560)); // ~8-9.3h
                $worked = $checkIn->diffInMinutes($checkOut);

                AttendanceRecord::updateOrCreate(
                    ['employee_id' => $employee->id, 'attendance_date' => $date->toDateString()],
                    [
                        'check_in_at' => $checkIn,
                        'check_out_at' => $checkOut,
                        'worked_minutes' => $worked,
                        'late_minutes' => $late,
                        'overtime_minutes' => $service->overtimeMinutes($employee, $worked),
                        'status' => $late > 0 ? AttendanceStatus::Late : AttendanceStatus::Present,
                        'check_in_source' => 'self',
                        'check_out_source' => 'self',
                    ],
                );
            }
        }
    }
}
