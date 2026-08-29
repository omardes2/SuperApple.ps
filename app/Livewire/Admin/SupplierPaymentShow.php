<?php

namespace App\Livewire\Admin;

use App\Models\FinancialAccount;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\ExchangeRateService;
use App\Services\SupplierPaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('دفعة مورد')]
class SupplierPaymentShow extends Component
{
    public SupplierPayment $payment;

    public string $payment_date = '';

    public string $currency = 'ILS';

    public string $amount = '0';

    public ?string $exchange_rate = null;

    public ?int $financial_account_id = null;

    public ?string $reference_number = null;

    public string $notes = '';

    /** @var list<array{bill_id:?int,allocated_original:string}> */
    public array $allocations = [];

    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(SupplierPayment $payment): void
    {
        $this->authorize('view', $payment);
        $this->payment = $payment;
        $this->fillFromModel();
    }

    private function fillFromModel(): void
    {
        $this->payment_date = $this->payment->payment_date->toDateString();
        $this->currency = $this->payment->currency;
        $this->amount = (string) $this->payment->amount;
        $this->exchange_rate = $this->payment->exchange_rate;
        $this->financial_account_id = $this->payment->financial_account_id;
        $this->reference_number = $this->payment->reference_number;
        $this->notes = (string) $this->payment->notes;

        if ($this->payment->isDraft()) {
            $this->allocations = [];
        }
    }

    public function suggestRate(ExchangeRateService $service): void
    {
        $this->exchange_rate = $service->suggestedRate($this->payment_date ?: now()->toDateString());
    }

    public function addAllocation(): void
    {
        $this->allocations[] = ['bill_id' => null, 'allocated_original' => ''];
    }

    public function removeAllocation(int $i): void
    {
        unset($this->allocations[$i]);
        $this->allocations = array_values($this->allocations);
    }

    public function autoAllocate(SupplierPaymentService $service): void
    {
        $this->persist($service);
        $available = Money::money($this->amount ?: 0);
        $rows = [];
        foreach ($this->openBills() as $bill) {
            if (Money::isZeroOrNegative($available)) {
                break;
            }
            $take = Money::isGreaterThan($bill->remaining_original, $available) ? $available : Money::money($bill->remaining_original);
            $rows[] = ['bill_id' => $bill->id, 'allocated_original' => $take];
            $available = Money::subtract($available, $take);
        }
        $this->allocations = $rows;
    }

    private function persist(SupplierPaymentService $service): void
    {
        $service->updateDraft($this->payment, [
            'payment_date' => $this->payment_date,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'exchange_rate' => $this->exchange_rate,
            'financial_account_id' => $this->financial_account_id,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
        ]);
        $this->payment->refresh();
    }

    public function save(SupplierPaymentService $service): void
    {
        $this->authorize('update', $this->payment);
        $this->validateForm();
        $this->persist($service);
        session()->flash('status', 'تم حفظ الدفعة.');
    }

    public function post(SupplierPaymentService $service): void
    {
        $this->authorize('post', $this->payment);
        $this->validateForm();
        $this->persist($service);

        $rows = [];
        foreach ($this->allocations as $a) {
            if (($a['bill_id'] ?? null) && $a['allocated_original'] !== '' && (float) $a['allocated_original'] > 0) {
                $rows[] = ['bill_id' => (int) $a['bill_id'], 'allocated_original' => $a['allocated_original']];
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
        session()->flash('status', 'تم ترحيل الدفعة وسداد الذمم الدائنة.');
    }

    public function openCancel(): void
    {
        $this->authorize('cancel', $this->payment);
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function confirmCancel(SupplierPaymentService $service): void
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

    private function validateForm(): void
    {
        $this->validate([
            'payment_date' => 'required|date',
            'currency' => 'required|in:ILS,USD',
            'amount' => 'required|numeric|gt:0',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'financial_account_id' => 'required|integer|exists:financial_accounts,id',
        ]);
    }

    private function openBills()
    {
        return SupplierBill::where('supplier_id', $this->payment->supplier_id)
            ->where('currency', $this->currency)
            ->openPayable()
            ->orderBy('bill_date')
            ->get();
    }

    public function render()
    {
        $this->payment->loadMissing(['supplier', 'financialAccount', 'allocations.bill']);

        $allocatedTotal = Money::sum(array_map(fn ($a) => $a['allocated_original'] === '' ? '0' : $a['allocated_original'], $this->allocations));

        return view('livewire.admin.supplier-payment-show', [
            'accounts' => FinancialAccount::active()->where('currency', $this->currency)->orderBy('name')->get(),
            'openBills' => $this->payment->isDraft() ? $this->openBills() : collect(),
            'allocatedTotal' => $allocatedTotal,
            'remaining' => Money::subtract($this->amount ?: 0, $allocatedTotal),
            'canEdit' => $this->payment->isDraft() && auth()->user()->can('supplier_payments.create'),
            'canPost' => $this->payment->isDraft() && auth()->user()->can('supplier_payments.post'),
        ]);
    }
}
