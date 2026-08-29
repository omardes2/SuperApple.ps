<?php

namespace App\Livewire\Employee;

use App\Models\PayrollItem;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('قسائم راتبي')]
class MyPayslips extends Component
{
    public function mount(): void
    {
        $this->authorize('payslips.view_own');
    }

    public function render()
    {
        $employee = Auth::user()->employee;

        // Only the employee's own payslips, and only from posted/paid runs.
        $items = $employee
            ? PayrollItem::where('employee_id', $employee->id)
                ->whereHas('payrollRun', fn ($q) => $q->whereIn('status', ['posted', 'paid']))
                ->with('payrollRun')->latest('id')->get()
            : collect();

        return view('livewire.employee.my-payslips', ['items' => $items]);
    }
}
