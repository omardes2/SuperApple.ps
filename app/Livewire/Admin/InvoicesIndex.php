<?php

namespace App\Livewire\Admin;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الفواتير')]
class InvoicesIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $customer = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('invoices.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'customer', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(InvoiceService $service)
    {
        $this->authorize('create', Invoice::class);

        $firstCustomer = Customer::active()->orderBy('name')->first() ?? Customer::orderBy('name')->first();
        abort_if($firstCustomer === null, 422);

        $invoice = $service->createDraft([
            'customer_id' => $firstCustomer->id,
            'invoice_date' => now()->toDateString(),
        ], []);

        return redirect()->route('admin.invoices.show', $invoice);
    }

    public function render()
    {
        $invoices = Invoice::query()
            ->with(['customer', 'project'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('invoice_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->when($this->customer !== '', fn ($q) => $q->where('customer_id', $this->customer))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);

        // Outstanding = sum of remaining_usd for issued, non-cancelled invoices.
        $outstanding = Invoice::issued()->sum('remaining_usd');
        $invoicedThisMonth = Invoice::issued()
            ->whereMonth('issued_at', now()->month)->whereYear('issued_at', now()->year)
            ->sum('total_usd');

        $overdue = Invoice::issued()
            ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString())
            ->where('remaining_usd', '>', 0)->count();

        $stats = [
            'draft' => Invoice::where('status', InvoiceStatus::Draft)->count(),
            'issued_month' => Invoice::issued()->whereMonth('issued_at', now()->month)->whereYear('issued_at', now()->year)->count(),
            'invoiced_month' => Money::money($invoicedThisMonth),
            'outstanding' => Money::money($outstanding),
            'overdue' => $overdue,
        ];

        return view('livewire.admin.invoices-index', [
            'invoices' => $invoices,
            'stats' => $stats,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'statusOptions' => InvoiceStatus::options(),
        ]);
    }
}
