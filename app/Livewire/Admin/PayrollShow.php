<?php

namespace App\Livewire\Admin;

use App\Models\FinancialAccount;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\PayrollPaymentService;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('مسير الرواتب')]
class PayrollShow extends Component
{
    public PayrollRun $run;

    public string $department = '';

    // Pay modal
    public bool $showPay = false;

    public ?int $payItemId = null;

    public string $payAmount = '0';

    public ?int $payAccountId = null;

    // Reverse / cancel modal
    public bool $showReverse = false;

    public string $reverseReason = '';

    public ?int $expandedItem = null;

    public function mount(PayrollRun $run): void
    {
        $this->authorize('view', $run);
        $this->run = $run;
    }

    private function act(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }
        $this->run->refresh();
        session()->flash('status', $message);
    }

    public function calculate(PayrollService $service): void
    {
        $this->authorize('calculate', $this->run);
        $this->act(fn () => $service->calculate($this->run), 'تم احتساب الرواتب.');
    }

    public function approve(PayrollService $service): void
    {
        $this->authorize('approve', $this->run);
        $this->act(fn () => $service->approve($this->run, Auth::user()), 'تم اعتماد الرواتب.');
    }

    public function post(PayrollService $service): void
    {
        $this->authorize('post', $this->run);
        $this->act(fn () => $service->post($this->run), 'تم ترحيل الرواتب محاسبياً.');
    }

    public function openReverse(): void
    {
        $this->authorize('reverse', $this->run);
        $this->reverseReason = '';
        $this->showReverse = true;
    }

    public function confirmReverse(PayrollService $service): void
    {
        $this->authorize('reverse', $this->run);
        try {
            $service->reverse($this->run, Auth::user(), $this->reverseReason);
        } catch (\RuntimeException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }
        $this->showReverse = false;
        $this->run->refresh();
        session()->flash('status', 'تم عكس مسير الرواتب.');
    }

    public function openPay(int $itemId): void
    {
        $this->authorize('pay', $this->run);
        $item = PayrollItem::findOrFail($itemId);
        $this->payItemId = $itemId;
        $this->payAmount = (string) $item->remaining_payable_ils;
        $this->payAccountId = FinancialAccount::active()->where('currency', 'ILS')->value('id');
        $this->showPay = true;
    }

    public function confirmPay(PayrollPaymentService $service): void
    {
        $this->authorize('pay', $this->run);
        $this->validate([
            'payAmount' => 'required|numeric|gt:0',
            'payAccountId' => 'required|integer|exists:financial_accounts,id',
        ]);

        try {
            $service->pay(PayrollItem::findOrFail($this->payItemId), $this->payAmount, $this->payAccountId);
        } catch (\RuntimeException $e) {
            $this->addError('payAmount', $e->getMessage());

            return;
        }

        $this->showPay = false;
        $this->run->refresh();
        session()->flash('status', 'تم دفع الراتب.');
    }

    public function toggleItem(int $itemId): void
    {
        $this->expandedItem = $this->expandedItem === $itemId ? null : $itemId;
    }

    public function render()
    {
        $items = $this->run->items()->with('employee')
            ->when($this->department !== '', fn ($q) => $q->where('department_snapshot', $this->department))
            ->orderBy('employee_name_snapshot')->get();

        return view('livewire.admin.payroll-show', [
            'items' => $items,
            'departments' => $this->run->items()->distinct()->pluck('department_snapshot')->filter()->values(),
            'accounts' => FinancialAccount::active()->where('currency', 'ILS')->orderBy('name')->get(),
            'canCalculate' => auth()->user()->can('calculate', $this->run),
            'canApprove' => auth()->user()->can('approve', $this->run),
            'canPost' => auth()->user()->can('post', $this->run),
            'canReverse' => auth()->user()->can('reverse', $this->run),
            'canPay' => auth()->user()->can('pay', $this->run),
        ]);
    }
}
