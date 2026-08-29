<?php

namespace App\Livewire\Admin;

use App\Enums\PaymentCurrency;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ExchangeRateService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الدفعة')]
class PaymentShow extends Component
{
    public Payment $payment;

    // ---- Draft form ----
    public ?int $customer_id = null;

    public string $payment_date = '';

    public string $payment_currency = 'USD';

    public string $payment_amount = '0';

    public ?string $exchange_rate = null;

    public string $payment_method = 'cash';

    public ?string $reference_number = null;

    public string $notes = '';

    /**
     * Allocation rows the accountant is composing before posting.
     *
     * @var list<array{invoice_id:?int,allocated_usd:string}>
     */
    public array $allocations = [];

    // ---- Cancel modal ----
    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(Payment $payment): void
    {
        $this->authorize('view', $payment);
        $this->payment = $payment;
        $this->fillFromModel();

        // "Record payment from invoice" deep-link: prefill customer + one row.
        $invoiceId = (int) request()->query('invoice', 0);
        if ($invoiceId > 0 && $this->payment->isDraft()) {
            $invoice = Invoice::find($invoiceId);
            if ($invoice && $invoice->acceptsAllocation()) {
                $this->customer_id = $invoice->customer_id;
                $this->allocations = [[
                    'invoice_id' => $invoice->id,
                    'allocated_usd' => Money::money($invoice->remaining_usd),
                ]];
            }
        }
    }

    private function fillFromModel(): void
    {
        $this->customer_id = $this->payment->customer_id;
        $this->payment_date = $this->payment->payment_date->toDateString();
        $this->payment_currency = $this->payment->payment_currency->value;
        $this->payment_amount = (string) $this->payment->payment_amount;
        $this->exchange_rate = $this->payment->exchange_rate;
        $this->payment_method = $this->payment->payment_method->value;
        $this->reference_number = $this->payment->reference_number;
        $this->notes = (string) $this->payment->notes;

        if ($this->payment->isDraft()) {
            $this->allocations = [];
        }
    }

    // ---- Draft actions ----

    public function suggestRate(ExchangeRateService $service): void
    {
        $this->authorize('update', $this->payment);
        $this->exchange_rate = $service->suggestedRate($this->payment_date ?: now()->toDateString());
    }

    public function addAllocationRow(): void
    {
        $this->allocations[] = ['invoice_id' => null, 'allocated_usd' => ''];
    }

    public function removeAllocationRow(int $index): void
    {
        unset($this->allocations[$index]);
        $this->allocations = array_values($this->allocations);
    }

    public function autoAllocate(PaymentService $service): void
    {
        $this->authorize('allocate', $this->payment);
        $this->persistDraft($service);

        $plan = $service->autoAllocatePlan($this->payment->refresh());
        $this->allocations = array_map(fn ($row) => [
            'invoice_id' => $row['invoice_id'],
            'allocated_usd' => $row['allocated_usd'],
        ], $plan);

        if ($this->allocations === []) {
            session()->flash('status', 'لا توجد فواتير مفتوحة قابلة للتخصيص لهذا العميل.');
        }
    }

    public function save(PaymentService $service): void
    {
        $this->authorize('update', $this->payment);
        $this->validateDraft();
        $this->persistDraft($service);
        session()->flash('status', 'تم حفظ الدفعة (مسودة).');
    }

    public function post(PaymentService $service): void
    {
        $this->authorize('post', $this->payment);
        $this->validateDraft();
        $this->persistDraft($service);

        $rows = [];
        foreach ($this->allocations as $row) {
            if (($row['invoice_id'] ?? null) && $row['allocated_usd'] !== '' && (float) $row['allocated_usd'] > 0) {
                $rows[] = ['invoice_id' => (int) $row['invoice_id'], 'allocated_usd' => $row['allocated_usd']];
            }
        }

        try {
            $service->post($this->payment, $rows);
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->payment->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم ترحيل الدفعة وقفلها.');
    }

    public function openCancel(): void
    {
        $this->authorize('cancel', $this->payment);
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function confirmCancel(PaymentService $service): void
    {
        $this->authorize('cancel', $this->payment);

        try {
            $service->cancel($this->payment, Auth::user(), $this->cancelReason);
        } catch (\RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->showCancel = false;
        $this->payment->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم إلغاء الدفعة وعكس تخصيصاتها.');
    }

    private function validateDraft(): void
    {
        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'payment_date' => 'required|date',
            'payment_currency' => 'required|in:USD,ILS',
            'payment_amount' => 'required|numeric|gt:0',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'payment_method' => 'required|string',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ], [], [
            'customer_id' => 'العميل',
            'payment_date' => 'تاريخ الدفعة',
            'payment_amount' => 'المبلغ',
            'exchange_rate' => 'سعر الصرف',
        ]);

        if ($this->payment_currency === 'ILS' && (float) ($this->exchange_rate ?? 0) <= 0) {
            $this->addError('exchange_rate', 'سعر الصرف مطلوب لدفعات الشيكل.');
            $this->validate(['exchange_rate' => 'required']); // stop the flow
        }
    }

    private function persistDraft(PaymentService $service): void
    {
        $service->updateDraft($this->payment, [
            'customer_id' => $this->customer_id,
            'payment_date' => $this->payment_date,
            'payment_currency' => $this->payment_currency,
            'payment_amount' => $this->payment_amount,
            'exchange_rate' => $this->exchange_rate,
            'payment_method' => $this->payment_method,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
        ]);
        $this->payment->refresh();
    }

    /** Live USD-equivalent preview of the amount being entered. */
    public function getUsdPreviewProperty(): string
    {
        if ($this->payment_currency === PaymentCurrency::USD->value) {
            return Money::money($this->payment_amount ?: 0);
        }

        $rate = (float) ($this->exchange_rate ?? 0);
        if ($rate <= 0) {
            return '0.00';
        }

        return Money::convertIlsToUsd($this->payment_amount ?: 0, $this->exchange_rate);
    }

    public function render()
    {
        $this->payment->loadMissing(['customer', 'receivedBy', 'allocations.invoice']);

        // Open invoices for the selected customer (for the allocation editor).
        $openInvoices = collect();
        if ($this->payment->isDraft() && $this->customer_id) {
            $openInvoices = Invoice::where('customer_id', $this->customer_id)
                ->whereIn('status', ['issued', 'sent', 'partially_paid'])
                ->where('remaining_usd', '>', 0)
                ->orderByRaw('due_date is null, due_date asc')
                ->orderBy('invoice_date')
                ->get();
        }

        $allocatedTotal = Money::sum(array_map(
            fn ($r) => $r['allocated_usd'] === '' ? '0' : $r['allocated_usd'],
            $this->allocations
        ));

        return view('livewire.admin.payment-show', [
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'currencyOptions' => PaymentCurrency::options(),
            'methodOptions' => PaymentMethod::options(),
            'openInvoices' => $openInvoices,
            'usdPreview' => $this->usdPreview,
            'allocatedTotal' => $allocatedTotal,
            'unallocatedPreview' => Money::subtract($this->usdPreview, $allocatedTotal),
            'canEdit' => $this->payment->isDraft() && auth()->user()->can('payments.edit'),
            'canPost' => $this->payment->isDraft() && auth()->user()->can('payments.post'),
        ]);
    }
}
