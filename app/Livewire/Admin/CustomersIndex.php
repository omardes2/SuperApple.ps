<?php

namespace App\Livewire\Admin;

use App\Enums\CustomerSource;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Services\CustomerService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('العملاء')]
class CustomersIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $city = '';

    #[Url]
    public string $source = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    // Customer form (NO email field — phone / whatsapp are the channels).
    public ?string $customer_number = null;

    public string $name = '';

    public string $contact_person = '';

    public string $phone = '';

    public string $whatsapp_number = '';

    public string $customer_city = '';

    public string $address = '';

    public string $tax_number = '';

    public ?int $customer_category_id = null;

    public string $customer_status = 'lead';

    public string $customer_source = '';

    public string $notes = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('customers.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'category', 'status', 'city', 'source'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('customers.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('customers.edit');
        $c = Customer::findOrFail($id);
        $this->editingId = $c->id;
        $this->customer_number = $c->customer_number;
        $this->name = $c->name;
        $this->contact_person = (string) $c->contact_person;
        $this->phone = (string) $c->phone;
        $this->whatsapp_number = (string) $c->whatsapp_number;
        $this->customer_city = (string) $c->city;
        $this->address = (string) $c->address;
        $this->tax_number = (string) $c->tax_number;
        $this->customer_category_id = $c->customer_category_id;
        $this->customer_status = $c->status->value;
        $this->customer_source = $c->source?->value ?? '';
        $this->notes = (string) $c->notes;
        $this->is_active = $c->is_active;
        $this->showForm = true;
    }

    public function save(CustomerService $service): void
    {
        $this->authorize($this->editingId ? 'customers.edit' : 'customers.create');

        $validated = $this->validate([
            'name' => 'required|string|max:150',
            'customer_number' => ['nullable', 'string', 'max:50', Rule::unique('customers', 'customer_number')->ignore($this->editingId)],
            'contact_person' => 'nullable|string|max:120',
            'phone' => 'required|string|max:40',
            'whatsapp_number' => 'nullable|string|max:40',
            'customer_city' => 'nullable|string|max:80',
            'address' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:60',
            'customer_category_id' => 'nullable|integer|exists:customer_categories,id',
            'customer_status' => ['required', Rule::enum(CustomerStatus::class)],
            'customer_source' => ['nullable', Rule::enum(CustomerSource::class)],
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
        ]);

        $data = [
            'name' => $validated['name'],
            'customer_number' => $validated['customer_number'] ?? null,
            'contact_person' => $validated['contact_person'] ?? null,
            'phone' => $validated['phone'],
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'city' => $validated['customer_city'] ?? null,
            'address' => $validated['address'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'customer_category_id' => $validated['customer_category_id'] ?? null,
            'status' => $validated['customer_status'],
            'source' => $validated['customer_source'] ?: null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $validated['is_active'],
        ];

        if ($this->editingId) {
            $service->update(Customer::findOrFail($this->editingId), $data);
            session()->flash('status', 'تم تحديث العميل.');
        } else {
            $service->create($data);
            session()->flash('status', 'تم إضافة العميل.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function archive(int $id, CustomerService $service): void
    {
        $this->authorize('customers.archive');
        $service->archive(Customer::findOrFail($id));
        session()->flash('status', 'تمت أرشفة العميل.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'customer_number', 'name', 'contact_person', 'phone',
            'whatsapp_number', 'customer_city', 'address', 'tax_number',
            'customer_category_id', 'customer_source', 'notes',
        ]);
        $this->customer_status = 'lead';
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $customers = Customer::query()
            ->with('category')
            ->withCount('tasks')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('customer_number', 'like', "%{$this->search}%")
                ->orWhere('contact_person', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('whatsapp_number', 'like', "%{$this->search}%")))
            ->when($this->category !== '', fn ($q) => $q->where('customer_category_id', $this->category))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->city !== '', fn ($q) => $q->where('city', 'like', "%{$this->city}%"))
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source))
            ->latest()
            ->paginate(15);

        $stats = [
            'total' => Customer::count(),
            'active' => Customer::where('status', CustomerStatus::Active)->count(),
            'leads' => Customer::where('status', CustomerStatus::Lead)->count(),
            'inactive' => Customer::whereIn('status', [CustomerStatus::Inactive, CustomerStatus::Archived])->count(),
        ];

        return view('livewire.admin.customers-index', [
            'customers' => $customers,
            'stats' => $stats,
            'categories' => CustomerCategory::orderBy('sort_order')->orderBy('name')->get(),
            'statusOptions' => CustomerStatus::options(),
            'sourceOptions' => CustomerSource::options(),
        ]);
    }
}
