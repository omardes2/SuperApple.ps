<?php

namespace App\Livewire\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\CustomerStatus;
use App\Enums\LeaveStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\AttendanceRecord;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Project;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الرئيسية')]
class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $hr = null;

        // HR operational cards — only computed for users allowed to see them.
        if ($user->canAny(['employees.view', 'attendance.view', 'leaves.view'])) {
            $today = now()->toDateString();
            $dayRecords = AttendanceRecord::whereDate('attendance_date', $today)->get();
            $activeCount = Employee::active()->count();
            $present = $dayRecords->whereNotNull('check_in_at')->pluck('employee_id')->unique()->count();
            $onLeave = $dayRecords->where('status', AttendanceStatus::Leave)->count();

            $hr = [
                'total_employees' => Employee::count(),
                'present' => $present,
                'late' => $dayRecords->where('status', AttendanceStatus::Late)->count(),
                'on_leave' => $onLeave,
                'absent' => max(0, $activeCount - $present - $onLeave),
                'pending_leaves' => LeaveRequest::where('status', LeaveStatus::Pending)->count(),
            ];
        }

        // Operational cards (customers / projects / tasks).
        $ops = null;
        if ($user->canAny(['customers.view', 'projects.view', 'tasks.view'])) {
            $ops = [
                'active_customers' => Customer::where('status', CustomerStatus::Active)->count(),
                'active_projects' => Project::where('status', ProjectStatus::Active)->count(),
                'tasks_today' => Task::whereDate('due_date', now()->toDateString())->count(),
                'late_tasks' => Task::late()->count(),
                'waiting_review' => Task::where('status', TaskStatus::WaitingReview)->count(),
            ];
        }

        // Financial cards — computed only for finance users (accountant / GM),
        // never for employees / PM / HR. USD is the official currency.
        $finance = null;
        if ($user->can('payments.view')) {
            $collected = Payment::posted()
                ->whereMonth('payment_date', now()->month)->whereYear('payment_date', now()->year)
                ->sum('usd_equivalent');
            $outstanding = Invoice::issued()->sum('remaining_usd');
            $postedUsd = Payment::posted()->sum('usd_equivalent');
            $allocatedUsd = PaymentAllocation::active()->whereHas('payment', fn ($q) => $q->posted())->sum('allocated_usd');
            $exchangeNet = PaymentAllocation::active()->whereHas('payment', fn ($q) => $q->posted()
                ->whereMonth('payment_date', now()->month)->whereYear('payment_date', now()->year))
                ->sum('exchange_difference_ils');

            $finance = [
                'collected_month_usd' => Money::money($collected),
                'outstanding_usd' => Money::money($outstanding),
                'unallocated_credit_usd' => Money::subtract($postedUsd, $allocatedUsd),
                'exchange_net_ils' => Money::money($exchangeNet),
            ];
        }

        return view('livewire.admin.dashboard', ['hr' => $hr, 'ops' => $ops, 'finance' => $finance]);
    }
}
