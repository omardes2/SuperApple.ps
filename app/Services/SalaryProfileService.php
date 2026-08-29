<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSalaryProfile;
use App\Models\PayrollItem;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Effective-dated salary profiles. A raise creates a NEW profile with a new
 * effective_from (and closes the previous one) — historical salaries used by
 * approved/posted payroll are never mutated.
 */
class SalaryProfileService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function setSalary(Employee $employee, array $data): EmployeeSalaryProfile
    {
        $effectiveFrom = $data['effective_from'] ?? now()->toDateString();

        // Close the currently-open profile the day before the new one starts.
        $current = $employee->salaryProfiles()
            ->whereNull('effective_to')
            ->orderByDesc('effective_from')->first();
        if ($current && $current->effective_from->toDateString() < $effectiveFrom) {
            $current->update(['effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(), 'status' => 'archived']);
        }

        $profile = $employee->salaryProfiles()->create([
            'effective_from' => $effectiveFrom,
            'effective_to' => $data['effective_to'] ?? null,
            'base_salary_ils' => Money::money($data['base_salary_ils'] ?? 0),
            'salary_type' => $data['salary_type'] ?? 'monthly',
            'working_days_basis' => $data['working_days_basis'] ?? null,
            'daily_rate' => isset($data['daily_rate']) && $data['daily_rate'] !== '' ? Money::money($data['daily_rate']) : null,
            'hourly_rate' => isset($data['hourly_rate']) && $data['hourly_rate'] !== '' ? Money::money($data['hourly_rate']) : null,
            'overtime_rate' => isset($data['overtime_rate']) && $data['overtime_rate'] !== '' ? Money::money($data['overtime_rate']) : null,
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        $this->audit->log('salary_profile_created', $profile, 'Payroll',
            new: ['base_salary_ils' => $profile->base_salary_ils, 'effective_from' => $effectiveFrom],
            description: "تحديد راتب {$employee->full_name}");

        return $profile;
    }

    /**
     * Edit descriptive fields of a profile not yet used in an approved/posted
     * payroll. Changing the salary amount/dates requires a new profile.
     *
     * @param  array<string,mixed>  $data
     */
    public function update(EmployeeSalaryProfile $profile, array $data): EmployeeSalaryProfile
    {
        if ($this->isUsedInApprovedPayroll($profile)) {
            throw new RuntimeException('لا يمكن تعديل راتب مستخدم في مسير معتمد — أنشئ راتباً جديداً بتاريخ سريان جديد.');
        }

        $profile->update([
            'base_salary_ils' => isset($data['base_salary_ils']) ? Money::money($data['base_salary_ils']) : $profile->base_salary_ils,
            'overtime_rate' => array_key_exists('overtime_rate', $data) && $data['overtime_rate'] !== '' ? Money::money($data['overtime_rate']) : $profile->overtime_rate,
            'notes' => $data['notes'] ?? $profile->notes,
        ]);

        return $profile;
    }

    private function isUsedInApprovedPayroll(EmployeeSalaryProfile $profile): bool
    {
        return PayrollItem::where('employee_id', $profile->employee_id)
            ->whereHas('payrollRun', fn ($q) => $q->whereIn('status', ['approved', 'posted', 'paid']))
            ->whereHas('payrollRun', fn ($q) => $q
                ->whereDate('period_start', '>=', $profile->effective_from)
                ->when($profile->effective_to, fn ($q) => $q->whereDate('period_end', '<=', $profile->effective_to)))
            ->exists();
    }
}
