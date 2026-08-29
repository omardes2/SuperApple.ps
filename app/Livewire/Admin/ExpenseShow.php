<?php

namespace App\Livewire\Admin;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Project;
use App\Models\Supplier;
use App\Services\ExpenseService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('المصروف')]
class ExpenseShow extends Component
{
    public Expense $expense;

    public string $expense_date = '';

    public ?int $category_id = null;

    public ?int $supplier_id = null;

    public ?int $project_id = null;

    public string $description = '';

    public string $currency = 'ILS';

    public string $amount = '0';

    public ?string $exchange_rate = null;

    public ?int $financial_account_id = null;

    public string $payment_method = 'cash';

    public ?string $reference_number = null;

    public string $notes = '';

    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(Expense $expense): void
    {
        $this->authorize('view', $expense);
        $this->expense = $expense;
        $this->fillFromModel();
    }

    private function fillFromModel(): void
    {
        $this->expense_date = $this->expense->expense_date->toDateString();
        $this->category_id = $this->expense->category_id;
        $this->supplier_id = $this->expense->supplier_id;
        $this->project_id = $this->expense->project_id;
        $this->description = (string) $this->expense->description;
        $this->currency = $this->expense->currency;
        $this->amount = (string) $this->expense->amount;
        $this->exchange_rate = $this->expense->exchange_rate;
        $this->financial_account_id = $this->expense->financial_account_id;
        $this->payment_method = $this->expense->payment_method ?? 'cash';
        $this->reference_number = $this->expense->reference_number;
        $this->notes = (string) $this->expense->notes;
    }

    private function rules(): array
    {
        return [
            'expense_date' => 'required|date',
            'category_id' => 'required|integer|exists:expense_categories,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'description' => 'required|string|max:255',
            'currency' => 'required|in:ILS,USD',
            'amount' => 'required|numeric|gt:0',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'payment_method' => 'nullable|string',
        ];
    }

    private function payload(): array
    {
        return [
            'expense_date' => $this->expense_date,
            'category_id' => $this->category_id,
            'supplier_id' => $this->supplier_id,
            'project_id' => $this->project_id,
            'description' => $this->description,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'exchange_rate' => $this->exchange_rate,
            'financial_account_id' => $this->financial_account_id,
            'payment_method' => $this->payment_method,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
        ];
    }

    public function save(ExpenseService $service): void
    {
        $this->authorize('update', $this->expense);
        $this->validate($this->rules());
        $service->updateDraft($this->expense, $this->payload());
        $this->expense->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم حفظ المصروف.');
    }

    public function post(ExpenseService $service): void
    {
        $this->authorize('post', $this->expense);
        $this->validate($this->rules());
        $service->updateDraft($this->expense, $this->payload());

        try {
            $service->post($this->expense->refresh());
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->expense->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم ترحيل المصروف وقيده محاسبياً.');
    }

    public function openCancel(): void
    {
        $this->authorize('cancel', $this->expense);
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function confirmCancel(ExpenseService $service): void
    {
        $this->authorize('cancel', $this->expense);

        try {
            $service->cancel($this->expense, Auth::user(), $this->cancelReason);
        } catch (\RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->showCancel = false;
        $this->expense->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم إلغاء المصروف.');
    }

    public function getAmountIlsProperty(): string
    {
        if ($this->currency === 'ILS') {
            return Money::money($this->amount ?: 0);
        }
        $rate = (float) ($this->exchange_rate ?? 0);

        return $rate > 0 ? Money::convertUsdToIls($this->amount ?: 0, $this->exchange_rate) : '0.00';
    }

    public function render()
    {
        $this->expense->loadMissing(['category', 'financialAccount', 'supplier', 'project']);

        return view('livewire.admin.expense-show', [
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'accounts' => FinancialAccount::active()->orderBy('name')->get(),
            'canEdit' => ($this->expense->isDraft() || $this->expense->status === ExpenseStatus::Approved) && auth()->user()->can('expenses.edit'),
            'canPost' => ($this->expense->isDraft() || $this->expense->status === ExpenseStatus::Approved) && auth()->user()->can('expenses.post'),
            'amountIls' => $this->amountIls,
        ]);
    }
}
