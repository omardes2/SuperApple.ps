<?php

namespace App\Livewire\Admin;

use App\Models\Supplier;
use App\Services\SupplierBalanceService;
use App\Services\SupplierService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الموردون')]
class SuppliersIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public bool $showCreate = false;

    public string $name = '';

    public string $phone = '';

    public string $supplier_type = '';

    public function mount(): void
    {
        $this->authorize('suppliers.view');
    }

    public function openCreate(): void
    {
        $this->authorize('create', Supplier::class);
        $this->reset(['name', 'phone', 'supplier_type']);
        $this->showCreate = true;
    }

    public function save(SupplierService $service)
    {
        $this->authorize('create', Supplier::class);
        $this->validate(['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:40']);

        $supplier = $service->create([
            'name' => $this->name,
            'phone' => $this->phone ?: null,
            'supplier_type' => $this->supplier_type ?: null,
        ]);

        $this->showCreate = false;

        return redirect()->route('admin.suppliers.show', $supplier);
    }

    public function render(SupplierBalanceService $balances)
    {
        $suppliers = Supplier::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('supplier_number', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate(15);

        $outstanding = [];
        foreach ($suppliers as $supplier) {
            $outstanding[$supplier->id] = $balances->outstandingIls($supplier);
        }

        return view('livewire.admin.suppliers-index', [
            'suppliers' => $suppliers,
            'outstanding' => $outstanding,
        ]);
    }
}
