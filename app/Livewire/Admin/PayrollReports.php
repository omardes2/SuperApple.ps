<?php

namespace App\Livewire\Admin;

use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تقارير الرواتب')]
class PayrollReports extends Component
{
    #[Url]
    public string $run = '';

    #[Url]
    public string $tab = 'summary';

    public function mount(): void
    {
        $this->authorize('payroll.reports');
    }

    public function render()
    {
        $runs = PayrollRun::orderByDesc('year')->orderByDesc('month')->get();
        $selected = $this->run !== '' ? PayrollRun::find($this->run) : $runs->first();

        $byDepartment = [];
        if ($selected) {
            foreach ($selected->items()->get()->groupBy('department_snapshot') as $dept => $items) {
                $byDepartment[] = [
                    'department' => $dept ?: '—',
                    'count' => $items->count(),
                    'gross' => Money::sum($items->pluck('gross_salary_ils')),
                    'deductions' => Money::sum($items->pluck('total_deductions_ils')),
                    'net' => Money::sum($items->pluck('net_salary_ils')),
                ];
            }
        }

        // Outstanding salary payables (posted runs, remaining > 0).
        $outstanding = PayrollItem::whereHas('payrollRun', fn ($q) => $q->whereIn('status', ['posted', 'paid']))
            ->where('remaining_payable_ils', '>', 0)
            ->with('payrollRun')->orderByDesc('remaining_payable_ils')->get();

        return view('livewire.admin.payroll-reports', [
            'runs' => $runs,
            'selected' => $selected,
            'byDepartment' => $byDepartment,
            'outstanding' => $outstanding,
            'outstandingTotal' => Money::sum($outstanding->pluck('remaining_payable_ils')),
            'advances' => EmployeeAdvance::with('employee')->whereIn('status', ['paid', 'partially_recovered', 'recovered'])->latest('id')->get(),
            'payments' => PayrollPayment::with(['item', 'financialAccount'])->where('status', 'posted')->latest('id')->limit(50)->get(),
        ]);
    }
}
