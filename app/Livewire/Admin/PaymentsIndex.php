<?php

namespace App\Livewire\Admin;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\PaymentService;
use App\Services\ReportsService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
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
     * Open the "new payment" page. It persists NOTHING until the user posts (or
     * explicitly saves a draft), so clicking "+ دفعة" no longer litters the
     * database with abandoned draft payments.
     */
    public function create()
    {
        $this->authorize('create', Payment::class);

        return redirect()->route('admin.payments.create');
    }

    /**
     * Delete a payment of any status. A draft is removed outright; a posted
     * payment is reversed first (invoices restored + GL mirror-reversed) so the
     * accounting effect is fully undone before the record is deleted.
     */
    public function deletePayment(int $id, PaymentService $service): void
    {
        $payment = Payment::findOrFail($id);
        $this->authorize('delete', $payment);

        try {
            $service->delete($payment, Auth::user());
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'تم حذف الدفعة وعكس قيودها المحاسبية.');
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
            'collected_month_ils' => app(ReportsService::class)->collectedThisMonthIls(),
            'unallocated_credit_usd' => Money::subtract($postedUsd, $allocatedUsd),
            'posted_count' => Payment::posted()->count(),
            'cancelled_count' => Payment::where('status', PaymentStatus::Cancelled)->count(),
        ];
    }
}
