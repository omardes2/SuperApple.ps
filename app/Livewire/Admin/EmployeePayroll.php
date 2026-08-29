<?php

namespace App\Livewire\Admin;

use App\Models\Account;
use App\Models\Employee;
use App\Services\SalaryProfileService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الرواتب والسلف')]
class EmployeePayroll extends Component
{
    public Employee $employee;

    // Salary form
    public bool $showSalary = false;

    public string $base_salary_ils = '0';

    public string $effective_from = '';

    public string $overtime_rate = '';

    // Adjustment form
    public bool $showAdjustment = false;

    public string $adjustment_type = 'earning';

    public string $category = 'bonus';

    public string $adjustment_amount = '0';

    public string $adjustment_desc = '';

    public bool $is_recurring = false;

    public ?int $gl_account_id = null;

    public function mount(Employee $employee): void
    {
        $this->authorize('salary_profiles.view');
        $this->employee = $employee;
        $this->effective_from = now()->startOfMonth()->toDateString();
    }

    public function openSalary(): void
    {
        $this->authorize('salary_profiles.manage');
        $this->showSalary = true;
    }

    public function saveSalary(SalaryProfileService $service): void
    {
        $this->authorize('salary_profiles.manage');
        $this->validate([
            'base_salary_ils' => 'required|numeric|gt:0',
            'effective_from' => 'required|date',
            'overtime_rate' => 'nullable|numeric|gte:0',
        ]);

        $service->setSalary($this->employee, [
            'base_salary_ils' => $this->base_salary_ils,
            'effective_from' => $this->effective_from,
            'overtime_rate' => $this->overtime_rate ?: null,
        ]);

        $this->showSalary = false;
        session()->flash('status', 'تم تحديد الراتب.');
    }

    public function openAdjustment(): void
    {
        $this->authorize('salary_adjustments.manage');
        $this->reset(['adjustment_type', 'category', 'adjustment_amount', 'adjustment_desc', 'is_recurring', 'gl_account_id']);
        $this->adjustment_type = 'earning';
        $this->category = 'bonus';
        $this->adjustment_amount = '0';
        $this->showAdjustment = true;
    }

    public function saveAdjustment(): void
    {
        $this->authorize('salary_adjustments.manage');
        $this->validate([
            'adjustment_type' => 'required|in:earning,deduction',
            'category' => 'required|string',
            'adjustment_amount' => 'required|numeric|gt:0',
        ]);

        $this->employee->salaryAdjustments()->create([
            'adjustment_type' => $this->adjustment_type,
            'category' => $this->category,
            'amount_ils' => Money::money($this->adjustment_amount),
            'effective_date' => now()->toDateString(),
            'description' => $this->adjustment_desc ?: null,
            'is_recurring' => $this->is_recurring,
            'gl_account_id' => $this->adjustment_type === 'deduction' ? $this->gl_account_id : null,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        $this->showAdjustment = false;
        session()->flash('status', 'تمت إضافة التعديل.');
    }

    public function render()
    {
        return view('livewire.admin.employee-payroll', [
            'profiles' => $this->employee->salaryProfiles()->orderByDesc('effective_from')->get(),
            'currentSalary' => $this->employee->salaryProfileOn(now()->toDateString()),
            'adjustments' => $this->employee->salaryAdjustments()->latest('effective_date')->get(),
            'advances' => $this->employee->advances()->latest('id')->get(),
            'payrollItems' => $this->employee->payrollItems()->with('payrollRun')->latest('id')->limit(24)->get(),
            'deductionAccounts' => Account::where('account_type', 'liability')->postable()->orderBy('code')->get(),
            'canManageSalary' => Auth::user()->can('salary_profiles.manage'),
            'canManageAdjustments' => Auth::user()->can('salary_adjustments.manage'),
        ]);
    }
}
