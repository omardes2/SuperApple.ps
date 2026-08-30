<?php

namespace App\Livewire\Admin;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryService;
use App\Services\ExpenseService;
use App\Support\Money;
use Illuminate\Validation\Rule;
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

    // ---- Category management modal ----
    public bool $showCategories = false;

    public ?int $editingCategoryId = null;

    public string $categoryName = '';

    public ?int $categoryAccountId = null;

    public bool $categoryActive = true;

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
        if ($category === null) {
            // No categories yet — never show a raw 422 error page. Guide the user.
            session()->flash('error', 'لا توجد فئات مصاريف فعّالة. أضف فئة أولاً من «تصنيفات المصروفات».');

            return null;
        }

        $expense = $service->createDraft([
            'category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'currency' => 'ILS',
            'amount' => 0,
            'description' => '',
        ]);

        return redirect()->route('admin.expenses.show', $expense);
    }

    // ---- Category management ----

    public function openCategories(): void
    {
        $this->authorize('expense_categories.manage');
        $this->resetCategoryForm();
        $this->showCategories = true;
    }

    public function newCategory(): void
    {
        $this->authorize('expense_categories.manage');
        $this->resetCategoryForm();
    }

    public function editCategory(int $id): void
    {
        $this->authorize('expense_categories.manage');
        $c = ExpenseCategory::findOrFail($id);
        $this->editingCategoryId = $c->id;
        $this->categoryName = $c->name;
        $this->categoryAccountId = $c->default_expense_account_id;
        $this->categoryActive = $c->is_active;
    }

    public function saveCategory(ExpenseCategoryService $service): void
    {
        $this->authorize('expense_categories.manage');

        $validated = $this->validate([
            'categoryName' => ['required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')->ignore($this->editingCategoryId)],
            'categoryAccountId' => 'required|integer|exists:chart_of_accounts,id',
            'categoryActive' => 'boolean',
        ], [], [
            'categoryName' => 'اسم الفئة',
            'categoryAccountId' => 'حساب الأستاذ',
        ]);

        $data = [
            'name' => $validated['categoryName'],
            'default_expense_account_id' => $validated['categoryAccountId'],
            'is_active' => $validated['categoryActive'],
        ];

        try {
            if ($this->editingCategoryId) {
                $service->update(ExpenseCategory::findOrFail($this->editingCategoryId), $data);
                session()->flash('status', 'تم تحديث الفئة.');
            } else {
                $service->create($data);
                session()->flash('status', 'تم إضافة الفئة.');
            }
        } catch (\RuntimeException $e) {
            $this->addError('categoryAccountId', $e->getMessage());

            return;
        }

        $this->resetCategoryForm();
    }

    public function toggleCategory(int $id, ExpenseCategoryService $service): void
    {
        $this->authorize('expense_categories.manage');
        $c = ExpenseCategory::findOrFail($id);
        $service->setActive($c, ! $c->is_active);
        session()->flash('status', $c->is_active ? 'تم تعطيل الفئة.' : 'تم تفعيل الفئة.');
    }

    private function resetCategoryForm(): void
    {
        $this->reset(['editingCategoryId', 'categoryName', 'categoryAccountId', 'categoryActive']);
        $this->categoryActive = true;
        $this->resetErrorBag();
    }

    public function render(ExpenseCategoryService $categoryService)
    {
        $expenses = Expense::query()
            ->with(['category', 'supplier', 'financialAccount'])
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

        $canManageCategories = auth()->user()->can('expense_categories.manage');

        return view('livewire.admin.expenses-index', [
            'expenses' => $expenses,
            'stats' => $stats,
            'categories' => ExpenseCategory::active()->orderBy('name')->get(),
            'statusOptions' => ExpenseStatus::options(),
            'canManageCategories' => $canManageCategories,
            'manageCategories' => $canManageCategories && $this->showCategories
                ? ExpenseCategory::with('defaultAccount')->orderBy('name')->get()
                : collect(),
            'eligibleAccounts' => $canManageCategories && $this->showCategories
                ? $categoryService->eligibleAccounts()
                : collect(),
        ]);
    }
}
