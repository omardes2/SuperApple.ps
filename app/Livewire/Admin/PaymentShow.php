<?php

namespace App\Livewire\Admin;

use App\Enums\FinancialAccountType;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
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

    /** The cash/bank account that receives the money ("إيداع في"). */
    public ?int $account_id = null;

    public ?string $reference_number = null;

    public string $notes = '';

    /**
     * Allocation rows the accountant is composing before posting.
     *
     * @var list<array{invoice_id:?int,allocated_usd:string}>
     */
    public array $allocations = [];

    /**
     * True while the current allocation rows were produced automatically (deep
     * link prefill or the "auto allocate" button) and the accountant has not
     * hand-edited them. In that mode the rows are re-derived from the live USD
     * equivalent whenever the amount/currency/rate changes; a manual edit turns
     * it off so we never overwrite the accountant's own numbers.
     */
    public bool $autoMode = false;

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
                // Prefill one row for this invoice, but never blindly allocate
                // its full remaining — cap at the payment's available USD. On a
                // fresh draft (amount not yet entered) this is 0, and it grows
                // as the accountant enters the amount/rate (see recalcAuto()).
                $this->allocations = [[
                    'invoice_id' => $invoice->id,
                    'allocated_usd' => '0.00',
                ]];
                $this->autoMode = true;
                $this->recalcAutoAllocations();
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
        $this->account_id = $this->payment->account_id;
        $this->reference_number = $this->payment->reference_number;
        $this->notes = (string) $this->payment->notes;

        if ($this->payment->isDraft()) {
            $this->allocations = [];
        }
    }

    /**
     * Livewire lifecycle: keep an auto allocation in sync with the amount, and
     * drop out of auto mode the moment the accountant edits a row by hand.
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'allocations.')) {
            $this->autoMode = false;

            return;
        }

        // Changing the customer invalidates any rows (they may point at another
        // customer's invoices) — clear them.
        if ($name === 'customer_id') {
            $this->allocations = [];
            $this->autoMode = false;

            return;
        }

        // The deposit account must match the payment currency; a currency change
        // invalidates a previously chosen account.
        if ($name === 'payment_currency') {
            $this->account_id = null;
        }

        if (in_array($name, ['payment_amount', 'payment_currency', 'exchange_rate'], true) && $this->autoMode) {
            $this->recalcAutoAllocations();
        }
    }

    // ---- Draft actions ----

    public function addAllocationRow(): void
    {
        // A hand-added row means the accountant is composing manually.
        $this->autoMode = false;
        $this->allocations[] = ['invoice_id' => null, 'opening_balance_id' => null, 'allocated_usd' => ''];
    }

    /** Add a row that allocates to the customer's open opening balance. */
    public function addOpeningBalanceRow(int $openingBalanceId): void
    {
        $this->autoMode = false;
        foreach ($this->allocations as $row) {
            if ((int) ($row['opening_balance_id'] ?? 0) === $openingBalanceId) {
                return; // already present
            }
        }
        $this->allocations[] = ['invoice_id' => null, 'opening_balance_id' => $openingBalanceId, 'allocated_usd' => ''];
    }

    public function removeAllocationRow(int $index): void
    {
        $this->autoMode = false;
        unset($this->allocations[$index]);
        $this->allocations = array_values($this->allocations);
    }

    /**
     * Re-derive the auto allocation rows from the live USD equivalent, oldest
     * row first: each invoice takes min(its remaining, the running available).
     * Never exceeds the payment's USD equivalent nor an invoice's remaining.
     * In-memory only — nothing is persisted until the draft is saved/posted.
     */
    private function recalcAutoAllocations(): void
    {
        $available = $this->usdPreview; // live USD equivalent of the amount entered

        foreach ($this->allocations as $i => $row) {
            // Each row targets an invoice or the opening balance.
            if (! empty($row['opening_balance_id'])) {
                $target = CustomerOpeningBalance::find($row['opening_balance_id']);
                $remaining = $target?->remaining_usd;
            } else {
                $target = ($row['invoice_id'] ?? null) ? Invoice::find($row['invoice_id']) : null;
                $remaining = $target?->remaining_usd;
            }

            if (! $target || Money::isZeroOrNegative($available)) {
                $this->allocations[$i]['allocated_usd'] = '0.00';

                continue;
            }

            $take = Money::isGreaterThan($remaining, $available)
                ? Money::money($available)
                : Money::money($remaining);

            $this->allocations[$i]['allocated_usd'] = $take;
            $available = Money::subtract($available, $take);
        }
    }

    public function autoAllocate(PaymentService $service): void
    {
        $this->authorize('allocate', $this->payment);
        $this->persistDraft($service);

        $plan = $service->autoAllocatePlan($this->payment->refresh());
        $this->allocations = array_map(fn ($row) => [
            'invoice_id' => $row['invoice_id'] ?? null,
            'opening_balance_id' => $row['opening_balance_id'] ?? null,
            'allocated_usd' => $row['allocated_usd'],
        ], $plan);
        // These rows are auto-derived — keep them in sync if the amount changes.
        $this->autoMode = true;

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
        // A destination cash/bank account is mandatory to post — the money has
        // to land somewhere. (The service re-checks existence/active/currency.)
        $this->validate(
            ['account_id' => 'required|integer|exists:financial_accounts,id'],
            ['account_id.required' => 'يجب تحديد حساب الإيداع (صندوق/بنك) قبل ترحيل الدفعة.'],
            ['account_id' => 'حساب الإيداع'],
        );
        $this->persistDraft($service);

        $rows = [];
        foreach ($this->allocations as $row) {
            if ($row['allocated_usd'] === '' || (float) $row['allocated_usd'] <= 0) {
                continue;
            }
            if (! empty($row['opening_balance_id'])) {
                $rows[] = ['opening_balance_id' => (int) $row['opening_balance_id'], 'allocated_usd' => $row['allocated_usd']];
            } elseif (! empty($row['invoice_id'])) {
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
            'account_id' => $this->account_id,
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
        $this->payment->loadMissing(['customer', 'receivedBy', 'allocations.invoice', 'allocations.openingBalance']);

        // Open invoices for the selected customer (for the allocation editor).
        $openInvoices = collect();
        $openOpeningBalance = null;
        if ($this->payment->isDraft() && $this->customer_id) {
            $openInvoices = Invoice::where('customer_id', $this->customer_id)
                ->whereIn('status', ['issued', 'sent', 'partially_paid'])
                ->where('remaining_usd', '>', 0)
                ->orderByRaw('due_date is null, due_date asc')
                ->orderBy('invoice_date')
                ->get();

            $openOpeningBalance = CustomerOpeningBalance::where('customer_id', $this->customer_id)
                ->posted()->debit()->where('remaining_usd', '>', 0)->first();
        }

        $allocatedTotal = Money::sum(array_map(
            fn ($r) => $r['allocated_usd'] === '' ? '0' : $r['allocated_usd'],
            $this->allocations
        ));

        // Deposit accounts the money can land in: active cash/bank accounts whose
        // currency matches this payment. USD is offered only if such an account
        // exists (we never force one into being).
        $depositAccounts = FinancialAccount::active()
            ->whereIn('type', [FinancialAccountType::Cash->value, FinancialAccountType::Bank->value])
            ->where('currency', $this->payment_currency)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency']);

        return view('livewire.admin.payment-show', [
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'currencyOptions' => PaymentCurrency::options(),
            'methodOptions' => PaymentMethod::options(),
            'depositAccounts' => $depositAccounts,
            'receivedAccount' => $this->payment->account_id ? FinancialAccount::find($this->payment->account_id) : null,
            'openInvoices' => $openInvoices,
            'openOpeningBalance' => $openOpeningBalance,
            'usdPreview' => $this->usdPreview,
            'allocatedTotal' => $allocatedTotal,
            'unallocatedPreview' => Money::subtract($this->usdPreview, $allocatedTotal),
            'canEdit' => $this->payment->isDraft() && auth()->user()->can('payments.edit'),
            'canPost' => $this->payment->isDraft() && auth()->user()->can('payments.post'),
        ]);
    }
}
