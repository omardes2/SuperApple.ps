<?php

namespace App\Livewire\Admin;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\PaymentService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الدفعات والتحصيل')]
class PaymentsIndex extends Component
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
        $this->authorize('payments.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'customer', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Create a draft for the first available customer and open it. The full
     * form (customer, date, currency, amount, allocations) lives on the detail
     * page so a draft is never posted from here.
     */
    public function create(PaymentService $service)
    {
        $this->authorize('create', Payment::class);

        $firstCustomer = Customer::active()->orderBy('name')->first() ?? Customer::orderBy('name')->first();
        abort_if($firstCustomer === null, 422, 'لا يوجد عملاء لإنشاء دفعة.');

        $payment = $service->createDraft([
            'customer_id' => $firstCustomer->id,
            'payment_date' => now()->toDateString(),
            'payment_currency' => 'USD',
            'payment_amount' => 0,
        ]);

        return redirect()->route('admin.payments.show', $payment);
    }

    public function render()
    {
        $payments = Payment::query()
            ->with('customer')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('payment_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->when($this->customer !== '', fn ($q) => $q->where('customer_id', $this->customer))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('payment_date')->latest('id')
            ->paginate(15);

        return view('livewire.admin.payments-index', [
            'payments' => $payments,
            'stats' => $this->stats(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'statusOptions' => PaymentStatus::options(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function stats(): array
    {
        $monthQuery = fn () => Payment::posted()
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year);

        // Collected this month, split by the currency the customer actually paid.
        $collectedUsd = $monthQuery()->sum('usd_equivalent');
        $ilsOriginal = (clone $monthQuery())->where('payment_currency', 'ILS')->sum('payment_amount');

        // Unallocated credit across all posted payments (customer credit not lost).
        $postedUsd = Payment::posted()->sum('usd_equivalent');
        $allocatedUsd = PaymentAllocation::active()
            ->whereHas('payment', fn ($q) => $q->posted())
            ->sum('allocated_usd');

        return [
            'collected_month_usd' => Money::money($collectedUsd),
            'collected_month_ils_original' => Money::money($ilsOriginal),
            'unallocated_credit_usd' => Money::subtract($postedUsd, $allocatedUsd),
            'posted_count' => Payment::posted()->count(),
            'cancelled_count' => Payment::where('status', PaymentStatus::Cancelled)->count(),
        ];
    }
}
