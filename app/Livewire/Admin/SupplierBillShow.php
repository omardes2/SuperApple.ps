<?php

namespace App\Livewire\Admin;

use App\Models\Account;
use App\Models\Project;
use App\Models\SupplierBill;
use App\Services\SupplierBillService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('فاتورة مورد')]
class SupplierBillShow extends Component
{
    public SupplierBill $bill;

    public string $bill_date = '';

    public ?string $due_date = null;

    public string $currency = 'ILS';

    public ?string $exchange_rate = null;

    public ?int $project_id = null;

    public ?string $reference_number = null;

    public string $notes = '';

    /** @var list<array{description:string,quantity:string,unit_price:string,tax:string,expense_account_id:?int}> */
    public array $items = [];

    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(SupplierBill $bill): void
    {
        $this->authorize('view', $bill);
        $this->bill = $bill;
        $this->fillFromModel();
    }

    private function fillFromModel(): void
    {
        $this->bill->loadMissing('items');
        $this->bill_date = $this->bill->bill_date->toDateString();
        $this->due_date = $this->bill->due_date?->toDateString();
        $this->currency = $this->bill->currency;
        $this->exchange_rate = $this->bill->exchange_rate;
        $this->project_id = $this->bill->project_id;
        $this->reference_number = $this->bill->reference_number;
        $this->notes = (string) $this->bill->notes;

        $this->items = $this->bill->items->map(fn ($i) => [
            'description' => $i->description,
            'quantity' => (string) $i->quantity,
            'unit_price' => (string) $i->unit_price,
            'tax' => (string) $i->tax,
            'expense_account_id' => $i->expense_account_id,
        ])->all();

        if ($this->items === [] && $this->bill->isDraft()) {
            $this->items = [['description' => '', 'quantity' => '1', 'unit_price' => '0', 'tax' => '0', 'expense_account_id' => null]];
        }
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'quantity' => '1', 'unit_price' => '0', 'tax' => '0', 'expense_account_id' => null];
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
    }

    private function payload(): array
    {
        return [
            'bill_date' => $this->bill_date,
            'due_date' => $this->due_date,
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'project_id' => $this->project_id,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
        ];
    }

    private function validateForm(): void
    {
        $this->validate([
            'bill_date' => 'required|date',
            'currency' => 'required|in:ILS,USD',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|gte:0',
        ]);
    }

    public function save(SupplierBillService $service): void
    {
        $this->authorize('update', $this->bill);
        $this->validateForm();
        $service->updateDraft($this->bill, $this->payload(), $this->items);
        $this->bill->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم حفظ الفاتورة.');
    }

    public function post(SupplierBillService $service): void
    {
        $this->authorize('post', $this->bill);
        $this->validateForm();
        $service->updateDraft($this->bill, $this->payload(), $this->items);

        try {
            $service->post($this->bill->refresh());
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->bill->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم ترحيل الفاتورة وقيدها في الذمم الدائنة.');
    }

    public function openCancel(): void
    {
        $this->authorize('cancel', $this->bill);
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function confirmCancel(SupplierBillService $service): void
    {
        $this->authorize('cancel', $this->bill);

        try {
            $service->cancel($this->bill, Auth::user(), $this->cancelReason);
        } catch (\RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->showCancel = false;
        $this->bill->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم إلغاء الفاتورة.');
    }

    public function getTotalProperty(): string
    {
        $total = '0.00';
        foreach ($this->items as $item) {
            $base = Money::multiply($item['quantity'] ?: 0, $item['unit_price'] ?: 0);
            $total = Money::add($total, Money::add($base, Money::money($item['tax'] ?: 0)));
        }

        return $total;
    }

    public function render()
    {
        $this->bill->loadMissing(['supplier', 'items']);

        return view('livewire.admin.supplier-bill-show', [
            'expenseAccounts' => Account::where('account_type', 'expense')->postable()->orderBy('code')->get(),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'canEdit' => $this->bill->isDraft() && auth()->user()->can('supplier_bills.edit'),
            'canPost' => $this->bill->isDraft() && auth()->user()->can('supplier_bills.post'),
            'total' => $this->total,
        ]);
    }
}
