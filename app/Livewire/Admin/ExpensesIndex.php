<?php

namespace App\Livewire\Admin;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('المصاريف')]
class ExpensesIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $category = '';

    public function mount(): void
    {
        $this->authorize('expenses.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'category'], true)) {
            $this->resetPage();
        }
    }

    public function create(ExpenseService $service)
    {
        $this->authorize('create', Expense::class);
        $category = ExpenseCategory::active()->orderBy('name')->first();
        abort_if($category === null, 422, 'لا توجد فئات مصاريف.');

        $expense = $service->createDraft([
            'category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'currency' => 'ILS',
            'amount' => 0,
            'description' => '',
        ]);

        return redirect()->route('admin.expenses.show', $expense);
    }

    public function render()
    {
        $expenses = Expense::query()
            ->with(['category', 'supplier', 'project', 'financialAccount'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('expense_number', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->category !== '', fn ($q) => $q->where('category_id', $this->category))
            ->latest('expense_date')->latest('id')
            ->paginate(15);

        $monthPosted = Expense::posted()
            ->whereMonth('expense_date', now()->month)->whereYear('expense_date', now()->year)
            ->sum('amount_ils');

        $stats = [
            'month_ils' => Money::money($monthPosted),
            'posted' => Expense::posted()->count(),
            'draft' => Expense::where('status', ExpenseStatus::Draft)->count(),
        ];

        return view('livewire.admin.expenses-index', [
            'expenses' => $expenses,
            'stats' => $stats,
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'statusOptions' => ExpenseStatus::options(),
        ]);
    }
}
