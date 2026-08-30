<?php

namespace App\Livewire\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\CustomerStatus;
use App\Enums\LeaveStatus;
use App\Enums\TaskStatus;
use App\Models\AttendanceRecord;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Task;
use App\Models\WhatsAppMessage;
use App\Services\AccountingReportService;
use App\Services\FinancialAccountService;
use App\Services\ReconciliationService;
use App\Services\ReportsService;
use App\Services\Settings;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الرئيسية')]
class Dashboard extends Component
{
    /** Chart window in months (6 or 12), user-selectable. */
    public int $chartMonths = 6;

    public function setChartMonths(int $months): void
    {
        $this->chartMonths = in_array($months, [6, 12], true) ? $months : 6;
    }

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

        // Operational cards (customers / tasks). Projects were retired.
        $ops = null;
        if ($user->canAny(['customers.view', 'tasks.view'])) {
            $ops = [
                'active_customers' => Customer::where('status', CustomerStatus::Active)->count(),
                'tasks_today' => Task::whereDate('due_date', now()->toDateString())->count(),
                'late_tasks' => Task::late()->count(),
                'waiting_review' => Task::where('status', TaskStatus::WaitingReview)->count(),
                'completed_month' => Task::where('status', TaskStatus::Completed)
                    ->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count(),
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

            $reportsSvc = app(ReportsService::class);
            $finance = [
                'collected_month_usd' => Money::money($collected),
                'collected_month_ils' => $reportsSvc->collectedThisMonthIls(),
                'outstanding_usd' => Money::money($outstanding),
                'outstanding_ils' => $reportsSvc->receivablesIlsByDocument(),
                'unallocated_credit_usd' => Money::subtract($postedUsd, $allocatedUsd),
                'exchange_net_ils' => Money::money($exchangeNet),
            ];
        }

        // Accounting cards (cash, AP, expenses, net profit) — accounting users.
        $accounting = null;
        if ($user->can('accounting.view') || $user->can('reports.profit_loss')) {
            $cashTotal = '0.00';
            foreach (FinancialAccount::all() as $fa) {
                $cashTotal = Money::add($cashTotal, app(FinancialAccountService::class)->balanceIls($fa));
            }
            $apGl = JournalEntryLine::whereHas('account', fn ($q) => $q->where('code', '2100'))
                ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))
                ->selectRaw('COALESCE(SUM(credit_ils)-SUM(debit_ils),0) as b')->value('b');
            $expMonth = Expense::posted()
                ->whereMonth('expense_date', now()->month)->whereYear('expense_date', now()->year)
                ->sum('amount_ils');
            $pl = app(AccountingReportService::class)->profitAndLoss(now()->startOfMonth()->toDateString(), now()->toDateString());

            $accounting = [
                'cash_ils' => Money::money($cashTotal),
                'payable_ils' => Money::money($apGl ?? 0),
                'expenses_month_ils' => Money::money($expMonth),
                'net_profit_month_ils' => $pl['net_profit'],
            ];
        }

        // Subscriptions module retired — no MRR/ARR cards.

        // ---- Executive analytics (finance/accounting users only) ----
        $reports = app(ReportsService::class);
        $charts = null;
        $aging = null;
        $topCustomers = null;

        if ($user->can('payments.view')) {
            $finance['revenue_month_ils'] = $reports->revenueThisMonthIls();
            $aging = $reports->arAging();
            $topCustomers = [
                'revenue' => $reports->topCustomersByRevenue(5),
                'outstanding' => $reports->topCustomersByOutstanding(5),
            ];
        }

        if ($user->can('accounting.view') || $user->can('reports.profit_loss')) {
            $charts = [
                'revenue_expense' => $reports->revenueVsExpenses($this->chartMonths),
                'cash' => $reports->cashCollectionByMonth($this->chartMonths),
            ];
        }

        $alerts = $this->executiveAlerts($user, $reports);

        return view('livewire.admin.dashboard', [
            'hr' => $hr, 'ops' => $ops, 'finance' => $finance, 'accounting' => $accounting,
            'charts' => $charts, 'aging' => $aging,
            'topCustomers' => $topCustomers, 'alerts' => $alerts,
        ]);
    }

    /**
     * Executive alerts against configurable Settings thresholds (never hard-coded).
     * Each alert is permission-gated.
     *
     * @return list<array{level:string,text:string,route?:string}>
     */
    private function executiveAlerts($user, ReportsService $reports): array
    {
        $settings = app(Settings::class);
        $alerts = [];

        if ($user->can('payments.view')) {
            $days = (int) $settings->get('dashboard', 'receivable_alert_days', 30);
            $overdue = Invoice::issued()->where('remaining_usd', '>', 0)
                ->whereNotNull('due_date')->whereDate('due_date', '<', now()->subDays($days)->toDateString())->count();
            if ($overdue > 0) {
                $alerts[] = ['level' => 'red', 'text' => "توجد {$overdue} فاتورة متأخرة أكثر من {$days} يوماً", 'route' => 'admin.reports.ar-aging'];
            }

            $largeUsd = (string) $settings->get('dashboard', 'large_balance_usd', 5000);
            $big = collect($reports->topCustomersByOutstanding(1))->first();
            if ($big && (float) $big['amount'] > (float) $largeUsd) {
                $alerts[] = ['level' => 'amber', 'text' => "رصيد كبير مستحق على {$big['customer']->name}: ".Format::usd($big['amount'])];
            }
        }

        if ($user->can('whatsapp.view')) {
            $failed = WhatsAppMessage::failed()->count();
            if ($failed > 0) {
                $alerts[] = ['level' => 'amber', 'text' => "{$failed} رسالة واتساب فاشلة", 'route' => 'admin.whatsapp'];
            }
        }

        if ($user->can('reports.reconciliation')) {
            foreach (app(ReconciliationService::class)->all() as $rec) {
                if (! ($rec['balanced'] ?? true)) {
                    $alerts[] = ['level' => 'red', 'text' => 'مطابقة غير متوازنة: '.($rec['label'] ?? ''), 'route' => 'admin.reports.reconciliation'];
                }
            }
        }

        return $alerts;
    }
}
