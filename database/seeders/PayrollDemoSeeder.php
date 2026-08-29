<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\EmployeeAdvanceService;
use App\Services\PayrollPaymentService;
use App\Services\PayrollService;
use App\Services\SalaryProfileService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * DEMO payroll data — salary profiles, an advance, one calculated+posted payroll
 * run, and a couple of salary payments. Figures are illustrative only. Keeps
 * accounting integrity (every posting balances; reconciliations pass).
 */
class PayrollDemoSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'accountant@superapple.ps')->first() ?? User::first());

        $salary = app(SalaryProfileService::class);
        $advances = app(EmployeeAdvanceService::class);
        $payroll = app(PayrollService::class);
        $payments = app(PayrollPaymentService::class);

        $bank = FinancialAccount::where('currency', 'ILS')->where('type', 'bank')->first();
        if ($bank === null) {
            Auth::logout();

            return;
        }

        // Salary profiles for active employees (varied base salaries).
        $employees = Employee::active()->orderBy('id')->get();
        foreach ($employees as $i => $employee) {
            $salary->setSalary($employee, [
                'base_salary_ils' => (string) (3500 + ($i % 5) * 500),
                'effective_from' => now()->startOfYear()->toDateString(),
                'overtime_rate' => '30',
            ]);
        }

        if ($employees->isEmpty()) {
            Auth::logout();

            return;
        }

        // One advance to the first employee, paid, recovered via payroll.
        $adv = $advances->create([
            'employee_id' => $employees->first()->id,
            'amount_ils' => '1200', 'installment_ils' => '400',
            'financial_account_id' => $bank->id,
        ]);
        $advances->approve($adv->fresh(), Auth::user());
        $advances->pay($adv->fresh());

        // A recurring transport allowance + a one-time bonus for a couple of staff.
        $employees->first()->salaryAdjustments()->create([
            'adjustment_type' => 'earning', 'category' => 'allowance', 'amount_ils' => '300',
            'effective_date' => now()->startOfYear()->toDateString(), 'is_recurring' => true,
            'description' => 'بدل مواصلات', 'status' => 'active', 'created_by' => Auth::id(),
        ]);
        if ($employees->count() > 1) {
            $employees->get(1)->salaryAdjustments()->create([
                'adjustment_type' => 'earning', 'category' => 'bonus', 'amount_ils' => '500',
                'effective_date' => now()->toDateString(), 'is_recurring' => false,
                'description' => 'مكافأة أداء', 'status' => 'active', 'created_by' => Auth::id(),
            ]);
        }

        // Run last month's payroll (attendance data is around "now").
        $period = now()->subMonthNoOverflow();
        if (PayrollRun::where('year', $period->year)->where('month', $period->month)->exists()) {
            Auth::logout();

            return;
        }
        $run = $payroll->createRun($period->year, $period->month);
        $payroll->calculate($run);
        $payroll->approve($run->fresh(), Auth::user());
        $payroll->post($run->fresh());

        // Pay the first two employees in full (leaves some outstanding for the demo).
        foreach ($run->items()->take(2)->get() as $item) {
            if ((float) $item->remaining_payable_ils > 0) {
                $payments->pay($item, $item->remaining_payable_ils, $bank->id);
            }
        }

        Auth::logout();
    }
}
