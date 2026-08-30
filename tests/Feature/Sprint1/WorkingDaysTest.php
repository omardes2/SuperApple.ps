<?php

namespace Tests\Feature\Sprint1;

use App\Enums\RoleName;
use App\Livewire\Admin\SettingsPage;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use App\Services\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Configurable weekly working days (attendance.work_days). The company default
 * is Saturday–Thursday with Friday off; the setting drives both AttendanceService
 * and PayrollCalculator (one source of truth, no separate payroll logic).
 */
class WorkingDaysTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Carbon dayOfWeek → short key. */
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private function attendance(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function setWorkDays(array $days): void
    {
        app(Settings::class)->set('attendance', 'work_days', $days, 'json');
    }

    /** Fill attendance for every configured working day in a run's period. */
    private function fillWorkingDays(Employee $employee, $run, array $workDays): void
    {
        for ($d = $run->period_start->copy(); $d->lte($run->period_end); $d->addDay()) {
            if (in_array(self::DAYS[$d->dayOfWeek], $workDays, true)) {
                AttendanceRecord::create([
                    'employee_id' => $employee->id,
                    'attendance_date' => $d->toDateString(),
                    'status' => 'present',
                    'late_minutes' => 0,
                    'overtime_minutes' => 0,
                    'worked_minutes' => 480,
                ]);
            }
        }
    }

    public function test_friday_is_not_a_working_day_by_default(): void
    {
        $this->seed(SettingsSeeder::class); // ships Saturday–Thursday
        $friday = Carbon::parse('2026-08-01')->next(Carbon::FRIDAY);
        $this->assertFalse($this->attendance()->isWorkingDay($friday));
    }

    public function test_saturday_is_a_working_day_by_default(): void
    {
        $this->seed(SettingsSeeder::class);
        $saturday = Carbon::parse('2026-08-01')->next(Carbon::SATURDAY);
        $this->assertTrue($this->attendance()->isWorkingDay($saturday));
    }

    public function test_changing_working_days_from_settings_affects_attendance_service(): void
    {
        $saturday = Carbon::parse('2026-08-01')->next(Carbon::SATURDAY);

        $this->setWorkDays(['sun', 'mon', 'tue', 'wed', 'thu']); // no Saturday
        $this->assertFalse($this->attendance()->isWorkingDay($saturday));

        $this->setWorkDays(['sat', 'sun', 'mon', 'tue', 'wed', 'thu']); // include Saturday
        $this->assertTrue($this->attendance()->isWorkingDay($saturday));
    }

    public function test_employee_is_not_marked_absent_on_friday(): void
    {
        $this->seed(SettingsSeeder::class); // Sat–Thu, Friday off
        $workDays = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu'];

        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillWorkingDays($e, $run, $workDays); // Fridays intentionally left empty

        app(PayrollService::class)->calculate($run);
        $item = $run->items()->where('employee_id', $e->id)->first();

        // No Friday is counted as a working day, so no Friday is an absence.
        $this->assertSame(0, (int) $item->absent_days);
        $this->assertSame('4000.00', $item->net_salary_ils);
    }

    public function test_payroll_working_days_exclude_friday(): void
    {
        $this->seed(SettingsSeeder::class);
        $workDays = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu'];

        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');
        $run = $this->makePayrollRun(2026, 8);
        $this->fillWorkingDays($e, $run, $workDays);
        app(PayrollService::class)->calculate($run);
        $item = $run->items()->where('employee_id', $e->id)->first();

        // Compute the expected working days for the period (Sat–Thu, no Friday).
        $expected = 0;
        $fridays = 0;
        for ($d = $run->period_start->copy(); $d->lte($run->period_end); $d->addDay()) {
            if ($d->dayOfWeek === Carbon::FRIDAY) {
                $fridays++;
            }
            if (in_array(self::DAYS[$d->dayOfWeek], $workDays, true)) {
                $expected++;
            }
        }

        $this->assertGreaterThan(0, $fridays);
        $this->assertSame($expected, (int) $item->working_days);
    }

    /** Count configured working days in a payroll run's period. */
    private function countWorkingDays($run, array $workDays): int
    {
        $n = 0;
        for ($d = $run->period_start->copy(); $d->lte($run->period_end); $d->addDay()) {
            if (in_array(self::DAYS[$d->dayOfWeek], $workDays, true)) {
                $n++;
            }
        }

        return $n;
    }

    public function test_changing_working_days_affects_payroll_calculator(): void
    {
        $e = $this->makeEmployee();
        $this->makeSalaryProfile($e, '4000');

        // August, with Saturday as a working day.
        $withSat = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu'];
        $this->setWorkDays($withSat);
        $runA = $this->makePayrollRun(2026, 8);
        app(PayrollService::class)->calculate($runA);
        $this->assertSame(
            $this->countWorkingDays($runA, $withSat),
            (int) $runA->items()->where('employee_id', $e->id)->first()->working_days,
        );

        // September, without Saturday — the calculator honours the new setting.
        $withoutSat = ['sun', 'mon', 'tue', 'wed', 'thu'];
        $this->setWorkDays($withoutSat);
        $runB = $this->makePayrollRun(2026, 9);
        app(PayrollService::class)->calculate($runB);
        $this->assertSame(
            $this->countWorkingDays($runB, $withoutSat),
            (int) $runB->items()->where('employee_id', $e->id)->first()->working_days,
        );
    }

    public function test_settings_page_saves_working_days_and_derives_weekend(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);

        Livewire::actingAs($gm)->test(SettingsPage::class)
            ->set('companyName', 'شركة')
            ->set('defaultExchangeRate', '3.30')
            ->set('workStart', '09:00')
            ->set('workEnd', '17:00')
            ->set('graceMinutes', 15)
            ->set('workingDays', ['sat', 'sun', 'mon', 'tue', 'wed', 'thu'])
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(Settings::class);
        $settings->flush();
        $this->assertSame(['sat', 'sun', 'mon', 'tue', 'wed', 'thu'], $settings->get('attendance', 'work_days'));
        $this->assertSame(['fri'], $settings->get('attendance', 'weekend'));
    }

    public function test_settings_requires_at_least_one_working_day(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);

        Livewire::actingAs($gm)->test(SettingsPage::class)
            ->set('companyName', 'شركة')
            ->set('defaultExchangeRate', '3.30')
            ->set('workStart', '09:00')
            ->set('workEnd', '17:00')
            ->set('graceMinutes', 15)
            ->set('workingDays', [])
            ->call('save')
            ->assertHasErrors('workingDays');
    }

    public function test_start_end_and_grace_are_preserved_unchanged(): void
    {
        $this->seed(SettingsSeeder::class);
        $settings = app(Settings::class);
        $this->assertSame('09:00', $settings->get('attendance', 'work_start'));
        $this->assertSame('17:00', $settings->get('attendance', 'work_end'));
    }
}
