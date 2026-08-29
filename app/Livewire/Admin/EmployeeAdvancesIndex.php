<?php

namespace App\Livewire\Admin;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\FinancialAccount;
use App\Services\EmployeeAdvanceService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('سلف الموظفين')]
class EmployeeAdvancesIndex extends Component
{
    use WithPagination;

    public bool $showCreate = false;

    public ?int $employee_id = null;

    public string $type = 'advance';

    public string $amount_ils = '0';

    public string $installment_ils = '';

    public ?int $financial_account_id = null;

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('advances.view');
    }

    public function openCreate(): void
    {
        $this->authorize('create', EmployeeAdvance::class);
        $this->reset(['employee_id', 'type', 'amount_ils', 'installment_ils', 'financial_account_id', 'notes']);
        $this->type = 'advance';
        $this->amount_ils = '0';
        $this->showCreate = true;
    }

    public function save(EmployeeAdvanceService $service): void
    {
        $this->authorize('create', EmployeeAdvance::class);
        $this->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'type' => 'required|in:advance,loan',
            'amount_ils' => 'required|numeric|gt:0',
            'installment_ils' => 'nullable|numeric|gte:0',
        ]);

        $service->create([
            'employee_id' => $this->employee_id,
            'type' => $this->type,
            'amount_ils' => $this->amount_ils,
            'installment_ils' => $this->installment_ils ?: null,
            'financial_account_id' => $this->financial_account_id,
            'notes' => $this->notes ?: null,
        ]);

        $this->showCreate = false;
        session()->flash('status', 'تم إنشاء السلفة.');
    }

    public function approve(int $id, EmployeeAdvanceService $service): void
    {
        $advance = EmployeeAdvance::findOrFail($id);
        $this->authorize('approve', $advance);
        $this->run(fn () => $service->approve($advance, Auth::user()), 'تم اعتماد السلفة.');
    }

    public function pay(int $id, EmployeeAdvanceService $service): void
    {
        $advance = EmployeeAdvance::findOrFail($id);
        $this->authorize('pay', $advance);
        $this->run(fn () => $service->pay($advance), 'تم دفع السلفة وقيدها محاسبياً.');
    }

    public function cancel(int $id, EmployeeAdvanceService $service): void
    {
        $advance = EmployeeAdvance::findOrFail($id);
        $this->authorize('cancel', $advance);
        $this->run(fn () => $service->cancel($advance, Auth::user(), 'إلغاء من القائمة'), 'تم إلغاء السلفة.');
    }

    private function run(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }
        session()->flash('status', $message);
    }

    public function render()
    {
        $advances = EmployeeAdvance::with(['employee', 'financialAccount'])
            ->latest('id')->paginate(15);

        $outstanding = EmployeeAdvance::whereIn('status', ['paid', 'partially_recovered'])->sum('remaining_ils');

        return view('livewire.admin.employee-advances-index', [
            'advances' => $advances,
            'outstanding' => Money::money($outstanding),
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'accounts' => FinancialAccount::active()->where('currency', 'ILS')->orderBy('name')->get(),
            'canCreate' => Auth::user()->can('advances.create'),
            'canApprove' => Auth::user()->can('advances.approve'),
            'canPay' => Auth::user()->can('advances.pay'),
            'canManage' => Auth::user()->can('advances.manage'),
        ]);
    }
}
