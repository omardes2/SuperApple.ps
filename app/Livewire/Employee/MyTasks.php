<?php

namespace App\Livewire\Employee;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ResolvesActingEmployee;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.employee')]
#[Title('مهامي')]
class MyTasks extends Component
{
    use ResolvesActingEmployee, WithPagination;

    #[Url]
    public string $filter = 'all';

    // ---- New-task form (employee self-service) ----
    public bool $showForm = false;

    public string $title = '';

    public string $description = '';

    // Customer operational lookup (no financial data exposed).
    public ?int $customer_id = null;

    public string $customerSearch = '';

    public string $task_priority = 'normal';

    public ?string $start_date = null;

    public ?string $due_date = null;

    // Services (operational multi-select — prices never exposed).
    public string $serviceSearch = '';

    /** @var array<int,int> */
    public array $selectedServiceIds = [];

    // Funded-ads campaign budget (operational only).
    public ?string $ad_budget_amount = null;

    public string $ad_budget_currency = 'ILS';

    public function mount(): void
    {
        $this->authorize('tasks.view_own');
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('tasks.create');
        $this->reset([
            'title', 'description', 'customer_id', 'customerSearch',
            'serviceSearch', 'selectedServiceIds', 'ad_budget_amount',
        ]);
        $this->task_priority = 'normal';
        $this->ad_budget_currency = 'ILS';
        // Both dates default to today; the employee may change either.
        $this->start_date = now()->toDateString();
        $this->due_date = now()->toDateString();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    // ---- Customer operational lookup ----

    public function selectCustomer(int $id): void
    {
        $customer = Customer::active()->find($id);
        if ($customer) {
            $this->customer_id = $customer->id;
            $this->customerSearch = $customer->name;
        }
    }

    public function clearCustomer(): void
    {
        $this->customer_id = null;
        $this->customerSearch = '';
    }

    // ---- Services multi-select ----

    public function toggleService(int $id): void
    {
        if (in_array($id, $this->selectedServiceIds, true)) {
            $this->selectedServiceIds = array_values(array_diff($this->selectedServiceIds, [$id]));
        } elseif (Service::active()->whereKey($id)->exists()) {
            $this->selectedServiceIds[] = $id;
        }

        // If no selected service needs a budget, clear the ad-budget fields so no
        // stale hidden value is ever saved.
        if (! $this->adBudgetRequired()) {
            $this->ad_budget_amount = null;
        }
    }

    /** Whether any selected service is a funded-ads (campaign budget) service. */
    public function adBudgetRequired(): bool
    {
        if ($this->selectedServiceIds === []) {
            return false;
        }

        return Service::whereIn('id', $this->selectedServiceIds)
            ->where('requires_ad_budget', true)->exists();
    }

    public function save(TaskService $service): void
    {
        // Backend gate — never trust the button alone.
        $this->authorize('tasks.create');
        $employee = $this->actingEmployee();

        $rules = [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'customer_id' => 'required|integer|exists:customers,id',
            'selectedServiceIds' => 'required|array|min:1',
            'selectedServiceIds.*' => 'integer|exists:services,id',
            'task_priority' => ['required', Rule::enum(Priority::class)],
            'start_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:start_date',
        ];

        $adRequired = $this->adBudgetRequired();
        if ($adRequired) {
            $rules['ad_budget_amount'] = 'required|numeric|gt:0';
            $rules['ad_budget_currency'] = ['required', Rule::in(['ILS', 'USD'])];
        }

        $validated = $this->validate($rules, [], [
            'customer_id' => 'العميل',
            'selectedServiceIds' => 'الخدمات',
            'ad_budget_amount' => 'قيمة الإعلانات الممولة',
        ]);

        // The customer must be a real active customer (operational lookup).
        abort_unless(Customer::active()->whereKey($validated['customer_id'])->exists(), 422);

        $service->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'customer_id' => $validated['customer_id'],
            'service_ids' => $this->selectedServiceIds,
            // The employee's own department, and self-assignment: an employee
            // without tasks.assign can only create work for themselves.
            'department_id' => $employee->department_id,
            'primary_assignee_id' => $employee->id,
            'priority' => $validated['task_priority'],
            'start_date' => $validated['start_date'],
            'due_date' => $validated['due_date'],
            'ad_budget_amount' => $adRequired ? $validated['ad_budget_amount'] : null,
            'ad_budget_currency' => $adRequired ? $validated['ad_budget_currency'] : null,
        ]);

        $this->showForm = false;
        session()->flash('status', 'تم إنشاء المهمة.');
    }

    public function render()
    {
        $user = Auth::user();
        $employee = $user->employee;

        // Each call returns a fresh query scoped to the user's visible tasks.
        $base = fn () => Task::query()->visibleTo($user);

        $counts = [
            'all' => $base()->count(),
            'today' => $base()->whereDate('due_date', now()->toDateString())->count(),
            'late' => $base()->late()->count(),
            'in_progress' => $base()->where('status', TaskStatus::InProgress)->count(),
            'completed' => $base()->where('status', TaskStatus::Completed)->count(),
        ];

        $query = $base()->with(['customer', 'services', 'activeMembers']);

        $query = match ($this->filter) {
            'today' => $query->whereDate('due_date', now()->toDateString()),
            'late' => $query->late(),
            'in_progress' => $query->where('status', TaskStatus::InProgress),
            'completed' => $query->where('status', TaskStatus::Completed),
            default => $query,
        };

        // Operational customer results (no financial data — id/name/number/whatsapp only).
        $customerResults = collect();
        if ($this->showForm && trim($this->customerSearch) !== '' && $this->customer_id === null) {
            $term = trim($this->customerSearch);
            $customerResults = Customer::active()
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('customer_number', 'like', "%{$term}%")
                    ->orWhere('whatsapp_number', 'like', "%{$term}%"))
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'customer_number', 'whatsapp_number']);
        }

        // Operational service results (safe picker columns only — NO prices).
        $serviceResults = collect();
        if ($this->showForm) {
            $sterm = trim($this->serviceSearch);
            $serviceResults = Service::active()
                ->when($sterm !== '', fn ($q) => $q->where('name', 'like', "%{$sterm}%")->orWhere('category', 'like', "%{$sterm}%"))
                ->orderBy('name')
                ->limit(20)
                ->get(Service::pickerColumns());
        }

        $selectedServices = $this->selectedServiceIds !== []
            ? Service::whereIn('id', $this->selectedServiceIds)->get(Service::pickerColumns())
            : collect();

        return view('livewire.employee.my-tasks', [
            'tasks' => $query->orderByRaw('due_date is null, due_date asc')->paginate(15),
            'counts' => $counts,
            'canCreate' => $user->can('tasks.create'),
            'priorityOptions' => Priority::options(),
            'customerResults' => $customerResults,
            'serviceResults' => $serviceResults,
            'selectedServices' => $selectedServices,
            'adBudgetRequired' => $this->adBudgetRequired(),
            'actingEmployeeId' => $employee?->id,
        ]);
    }
}
