<?php

namespace App\Livewire\Concerns;

use App\Enums\Priority;
use App\Models\Customer;
use App\Models\Service;
use App\Services\TaskService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The single, shared task-creation form used by BOTH the employee portal
 * (/employee/tasks) and the admin task list (/admin/tasks). One set of fields,
 * one validation, one operational customer/service lookup, one ad-budget rule,
 * and one call into TaskService::create — so every user who holds tasks.create
 * (Employee, Super Admin, General Manager, any custom role) gets exactly the
 * same modal and the same backend behaviour.
 *
 * The creator becomes the task's primary member ONLY when they have an employee
 * profile; an admin/super-admin without one creates the task with no initial
 * member (participants are added later from the task page). No legacy
 * primary-assignee / department / estimated-minutes fields.
 */
trait CreatesTasks
{
    public bool $showForm = false;

    public string $title = '';

    public string $description = '';

    // Operational customer lookup (no financial data ever exposed).
    public ?int $customer_id = null;

    public string $customerSearch = '';

    // Operational service multi-select (safe picker columns only — no prices).
    public string $serviceSearch = '';

    /** @var array<int,int> */
    public array $selectedServiceIds = [];

    public string $task_priority = 'normal';

    public ?string $start_date = null;

    public ?string $due_date = null;

    // Funded-ads campaign budget (operational only — never accounting).
    public ?string $ad_budget_amount = null;

    public string $ad_budget_currency = 'ILS';

    public function create(): void
    {
        $this->authorize('tasks.create');
        $this->reset([
            'title', 'description', 'customer_id', 'customerSearch',
            'serviceSearch', 'selectedServiceIds', 'ad_budget_amount',
        ]);
        $this->task_priority = 'normal';
        $this->ad_budget_currency = 'ILS';
        // Both dates default to today; either may be changed.
        $this->start_date = now()->toDateString();
        $this->due_date = now()->toDateString();
        $this->resetErrorBag();
        $this->showForm = true;
    }

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

    public function toggleService(int $id): void
    {
        if (in_array($id, $this->selectedServiceIds, true)) {
            $this->selectedServiceIds = array_values(array_diff($this->selectedServiceIds, [$id]));
        } elseif (Service::active()->whereKey($id)->exists()) {
            $this->selectedServiceIds[] = $id;
        }

        // No selected service needs a budget → clear the ad-budget fields so no
        // stale hidden value is ever saved.
        if (! $this->adBudgetRequired()) {
            $this->ad_budget_amount = null;
            $this->ad_budget_currency = 'ILS';
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

        // The creator becomes the primary member only when they are staff; an
        // admin/super-admin without an employee profile creates with no member.
        $employee = Auth::user()->employee;

        $service->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'customer_id' => $validated['customer_id'],
            'service_ids' => $this->selectedServiceIds,
            'department_id' => $employee?->department_id,
            'primary_assignee_id' => $employee?->id,
            'priority' => $validated['task_priority'],
            'start_date' => $validated['start_date'],
            'due_date' => $validated['due_date'],
            'ad_budget_amount' => $adRequired ? $validated['ad_budget_amount'] : null,
            'ad_budget_currency' => $adRequired ? $validated['ad_budget_currency'] : null,
        ]);

        $this->showForm = false;
        session()->flash('status', 'تم إنشاء المهمة.');
    }

    /**
     * View data the shared create-form partial needs. Merge into render().
     *
     * @return array<string,mixed>
     */
    protected function taskFormViewData(): array
    {
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

        return [
            'canCreate' => Auth::user()->can('tasks.create'),
            'priorityOptions' => Priority::options(),
            'customerResults' => $customerResults,
            'serviceResults' => $serviceResults,
            'selectedServices' => $selectedServices,
            'adBudgetRequired' => $this->adBudgetRequired(),
            'actingIsMember' => Auth::user()->employee !== null,
        ];
    }
}
