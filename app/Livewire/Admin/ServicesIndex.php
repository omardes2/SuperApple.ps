<?php

namespace App\Livewire\Admin;

use App\Enums\ServiceType;
use App\Models\Service;
use App\Services\ServiceCatalogService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الخدمات')]
class ServicesIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?string $service_code = null;

    public string $name = '';

    public string $category = '';

    public string $description = '';

    public string $service_type = 'one_time';

    public ?string $default_price_usd = null;

    public ?string $estimated_cost_ils = null;

    public ?string $tax_rate = null;

    public bool $is_active = true;

    public bool $requires_ad_budget = false;

    public function mount(): void
    {
        $this->authorize('services.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('services.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('services.edit');
        $s = Service::findOrFail($id);
        $this->editingId = $s->id;
        $this->service_code = $s->service_code;
        $this->name = $s->name;
        $this->category = (string) $s->category;
        $this->description = (string) $s->description;
        $this->service_type = $s->service_type->value;
        $this->is_active = $s->is_active;
        $this->requires_ad_budget = (bool) $s->requires_ad_budget;

        // Only preload financial fields for users allowed to see them.
        if ($this->canViewFinancial()) {
            $this->default_price_usd = $s->default_price_usd;
            $this->estimated_cost_ils = $s->estimated_cost_ils;
            $this->tax_rate = $s->tax_rate;
        }

        $this->showForm = true;
    }

    public function save(ServiceCatalogService $service): void
    {
        $this->authorize($this->editingId ? 'services.edit' : 'services.create');

        $rules = [
            'name' => 'required|string|max:150',
            'service_code' => ['nullable', 'string', 'max:50', Rule::unique('services', 'service_code')->ignore($this->editingId)],
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:2000',
            'service_type' => ['required', Rule::enum(ServiceType::class)],
            'is_active' => 'boolean',
            'requires_ad_budget' => 'boolean',
        ];

        if ($this->canViewFinancial()) {
            $rules['default_price_usd'] = 'nullable|numeric|min:0';
            $rules['estimated_cost_ils'] = 'nullable|numeric|min:0';
            $rules['tax_rate'] = 'nullable|numeric|min:0|max:100';
        }

        $validated = $this->validate($rules);

        $data = [
            'name' => $validated['name'],
            'service_code' => $validated['service_code'] ?? null,
            'category' => $validated['category'] ?? null,
            'description' => $validated['description'] ?? null,
            'service_type' => $validated['service_type'],
            'is_active' => $validated['is_active'],
            'requires_ad_budget' => $validated['requires_ad_budget'] ?? false,
        ];

        // Financial fields are only ever written by authorised users.
        if ($this->canViewFinancial()) {
            $data['default_price_usd'] = $validated['default_price_usd'] ?? null;
            $data['estimated_cost_ils'] = $validated['estimated_cost_ils'] ?? null;
            $data['tax_rate'] = $validated['tax_rate'] ?? null;
        }

        if ($this->editingId) {
            $service->update(Service::findOrFail($this->editingId), $data);
            session()->flash('status', 'تم تحديث الخدمة.');
        } else {
            $service->create($data);
            session()->flash('status', 'تم إضافة الخدمة.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function canViewFinancial(): bool
    {
        return auth()->user()->can('services.view_financial');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'service_code', 'name', 'category', 'description',
            'default_price_usd', 'estimated_cost_ils', 'tax_rate',
        ]);
        $this->service_type = 'one_time';
        $this->is_active = true;
        $this->requires_ad_budget = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        $services = Service::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('service_code', 'like', "%{$this->search}%")
                ->orWhere('category', 'like', "%{$this->search}%")))
            ->when($this->type !== '', fn ($q) => $q->where('service_type', $this->type))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.admin.services-index', [
            'services' => $services,
            'typeOptions' => ServiceType::options(),
            'canViewFinancial' => $this->canViewFinancial(),
        ]);
    }
}
