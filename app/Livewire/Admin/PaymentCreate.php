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
use App\Services\CustomerBalanceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Record a NEW customer payment WITHOUT first persisting a draft. Everything is
 * held in-memory here; only on "post" do we createDraft() + post() inside one
 * DB transaction (so an abandoned or invalid entry leaves no draft behind). All
 * accounting stays in PaymentService/PaymentAllocationService — this component
 * only gathers input and shows the customer's open receivables so the user can
 * pay the opening balance or a specific outstanding invoice in one click.
 */
#[Layout('layouts.app')]
#[Title('تسجيل دفعة')]
class PaymentCreate extends Component
{
    public ?int $customer_id = null;

    public string $customerSearch = '';

    public string $payment_date = '';

    public string $payment_currency = 'USD';

    public string $payment_amount = '0';

    public ?string $exchange_rate = null;

    public string $payment_method = 'cash';

    public ?int $account_id = null;

    public ?string $reference_number = null;

    public string $notes = '';

    /**
     * In-memory allocation rows (nothing persisted until post).
     *
     * @var list<array{invoice_id:?int,opening_balance_id:?int,allocated_usd:string}>
     */
    public array $allocations = [];

    public function mount(): void
    {
        $this->authorize('create', Payment::class);
        $this->payment_date = now()->toDateString();

        // Optional deep-link: /payments/create?invoice=ID prefills the customer
        // and a full-settlement row for that invoice (used by "record payment").
        $invoiceId = (int) request()->query('invoice', 0);
        if ($invoiceId > 0) {
            $invoice = Invoice::find($invoiceId);
            if ($invoice && $invoice->acceptsAllocation()) {
                $this->customer_id = $invoice->customer_id;
                $this->payInvoice($invoice->id);
            }
        }
    }

    public function updated(string $name): void
    {
        // A currency change invalidates a deposit account of the other currency.
        if ($name === 'payment_currency') {
            $this->account_id = null;
        }
    }

    // ------------------------------------------------------------ Customer

    public function selectCustomer(int $id): void
    {
        $customer = Customer::active()->find($id) ?? Customer::find($id);
        if (! $customer) {
            return;
        }
        $this->customer_id = $customer->id;
        $this->customerSearch = '';
        $this->allocations = [];
        $this->payment_amount = '0';
        $this->exchange_rate = null;
    }

    public function clearCustomer(): void
    {
        $this->customer_id = null;
        $this->customerSearch = '';
        $this->allocations = [];
        $this->payment_amount = '0';
        $this->exchange_rate = null;
    }

    // -------------------------------------------------- Pay a receivable

    /** Add (or toggle off) a full-settlement row for one outstanding invoice. */
    public function payInvoice(int $invoiceId): void
    {
        $invoice = Invoice::find($invoiceId);
        if (! $invoice || (int) $invoice->customer_id !== (int) $this->customer_id || ! $invoice->acceptsAllocation()) {
            return;
        }
        foreach ($this->allocations as $i => $row) {
            if ((int) ($row['invoice_id'] ?? 0) === $invoiceId) {
                $this->removeAllocationRow($i);

                return;
            }
        }
        $this->allocations[] = ['invoice_id' => $invoiceId, 'opening_balance_id' => null, 'allocated_usd' => Money::money($invoice->remaining_usd)];
        $this->recalcFromAllocations();
    }

    /** Add (or toggle off) a full-settlement row for the open opening balance. */
    public function payOpeningBalance(int $obId): void
    {
        $ob = CustomerOpeningBalance::find($obId);
        if (! $ob || (int) $ob->customer_id !== (int) $this->customer_id || ! $ob->acceptsAllocation()) {
            return;
        }
        foreach ($this->allocations as $i => $row) {
            if ((int) ($row['opening_balance_id'] ?? 0) === $obId) {
                $this->removeAllocationRow($i);

                return;
            }
        }
        $this->allocations[] = ['invoice_id' => null, 'opening_balance_id' => $obId, 'allocated_usd' => Money::money($ob->remaining_usd)];
        $this->recalcFromAllocations();
    }

    public function removeAllocationRow(int $index): void
    {
        unset($this->allocations[$index]);
        $this->allocations = array_values($this->allocations);
        $this->recalcFromAllocations();
    }

    /**
     * Set the received amount + rate to exactly settle the chosen receivables.
     * USD: amount = Σ allocated. ILS: convert each row at ITS target rate and
     * sum the shekels; when every row shares one rate we pin that rate so the
     * conversion is exact (the common single-document case), otherwise we leave
     * the rate for PaymentService to derive at post time. The user may still
     * edit the amount afterwards (partial / overpayment).
     */
    private function recalcFromAllocations(): void
    {
        if ($this->allocations === []) {
            $this->payment_amount = '0';

            return;
        }

        $totalUsd = Money::sum(array_map(fn ($r) => $r['allocated_usd'] === '' ? '0' : $r['allocated_usd'], $this->allocations));

        if ($this->payment_currency === PaymentCurrency::USD->value) {
            $this->payment_amount = Money::money($totalUsd);

            return;
        }

        // ILS: shekels per row at the row's own document rate; the received
        // amount is their sum. A shekel payment posts at ONE rate, so we pin a
        // single blended rate = total shekels ÷ total USD. With one document (or
        // several sharing a rate) this is exactly that rate — zero FX. When the
        // chosen documents carry different rates it is the only consistent rate:
        // the received shekels, the AR relief and the cash all tie out, and the
        // small per-invoice FX difference is booked by the allocation service.
        $ils = '0.00';
        foreach ($this->allocations as $row) {
            $rate = $this->rowRate($row);
            if ($rate !== null) {
                $ils = Money::add($ils, Money::convertUsdToIls($row['allocated_usd'] ?: '0', $rate));
            }
        }
        $this->payment_amount = Money::money($ils);
        $this->exchange_rate = ((float) $totalUsd > 0 && (float) $ils > 0)
            ? Money::rate((float) $ils / (float) $totalUsd)
            : null;
    }

    /** The locked rate of an allocation row's target document (invoice/OB). */
    private function rowRate(array $row): ?string
    {
        if (! empty($row['opening_balance_id'])) {
            $ob = CustomerOpeningBalance::find($row['opening_balance_id']);

            return $ob && Money::isPositive($ob->exchange_rate) ? (string) $ob->exchange_rate : null;
        }
        if (! empty($row['invoice_id'])) {
            $inv = Invoice::find($row['invoice_id']);

            return $inv && Money::isPositive($inv->exchange_rate) ? (string) $inv->exchange_rate : null;
        }

        return null;
    }

    // ------------------------------------------------------------ Persist

    public function save(PaymentService $service)
    {
        $this->authorize('create', Payment::class);
        $this->validateForm(requireAccount: false);

        $payment = $service->createDraft($this->draftData());
        session()->flash('status', 'تم حفظ الدفعة كمسودة.');

        return redirect()->route('admin.payments.show', $payment);
    }

    public function post(PaymentService $service)
    {
        $this->authorize('create', Payment::class);
        $this->validateForm(requireAccount: true);

        try {
            $payment = DB::transaction(function () use ($service) {
                // Create then immediately post inside one transaction: if posting
                // throws (validation/currency/allocation), the draft is rolled
                // back too — nothing is persisted.
                $payment = $service->createDraft($this->draftData());
                $service->post($payment, $this->allocationRows());

                return $payment;
            });
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return null;
        }

        session()->flash('status', 'تم ترحيل الدفعة وقفلها.');

        return redirect()->route('admin.payments.show', $payment);
    }

    private function validateForm(bool $requireAccount): void
    {
        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'payment_date' => 'required|date',
            'payment_currency' => 'required|in:USD,ILS',
            'payment_amount' => 'required|numeric|gt:0',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ], [], [
            'customer_id' => 'العميل',
            'payment_date' => 'تاريخ الدفعة',
            'payment_amount' => 'المبلغ',
            'exchange_rate' => 'سعر الصرف',
        ]);

        if ($requireAccount) {
            $this->validate(
                ['account_id' => 'required|integer|exists:financial_accounts,id'],
                ['account_id.required' => 'يجب تحديد حساب الإيداع (صندوق/بنك) قبل ترحيل الدفعة.'],
                ['account_id' => 'حساب الإيداع'],
            );

            $account = $this->account_id ? FinancialAccount::find($this->account_id) : null;
            if ($account && $account->currency !== $this->payment_currency) {
                throw ValidationException::withMessages([
                    'account_id' => "عملة حساب الإيداع ({$account->currency}) لا تطابق عملة الدفعة ({$this->payment_currency}).",
                ]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function draftData(): array
    {
        return [
            'customer_id' => $this->customer_id,
            'payment_date' => $this->payment_date,
            'payment_currency' => $this->payment_currency,
            'payment_amount' => $this->payment_amount,
            'exchange_rate' => $this->exchange_rate,
            'payment_method' => $this->payment_method,
            'account_id' => $this->account_id,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function allocationRows(): array
    {
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

        return $rows;
    }

    // ------------------------------------------------------------ Preview

    /** Live USD-equivalent of the amount entered (same rule as PaymentShow). */
    public function getUsdPreviewProperty(): string
    {
        if ($this->payment_currency === PaymentCurrency::USD->value) {
            return Money::money($this->payment_amount ?: 0);
        }
        $rate = (float) ($this->exchange_rate ?? 0) > 0 ? $this->exchange_rate : $this->ilsInvoiceRate();
        if ((float) ($rate ?? 0) <= 0) {
            return '0.00';
        }

        return Money::convertIlsToUsd($this->payment_amount ?: 0, $rate);
    }

    /** The rate an ILS payment converts at: first target, else oldest open doc. */
    public function ilsInvoiceRate(): ?string
    {
        foreach ($this->allocations as $row) {
            $rate = $this->rowRate($row);
            if ($rate !== null) {
                return $rate;
            }
        }
        if ($this->customer_id) {
            $inv = Invoice::where('customer_id', $this->customer_id)
                ->whereIn('status', ['issued', 'sent', 'partially_paid'])
                ->where('remaining_usd', '>', 0)
                ->orderByRaw('due_date is null, due_date asc')->orderBy('invoice_date')->first();
            if ($inv && Money::isPositive($inv->exchange_rate)) {
                return (string) $inv->exchange_rate;
            }
            $ob = CustomerOpeningBalance::where('customer_id', $this->customer_id)
                ->posted()->debit()->where('remaining_usd', '>', 0)->first();
            if ($ob && Money::isPositive($ob->exchange_rate)) {
                return (string) $ob->exchange_rate;
            }
        }

        return null;
    }

    public function render(CustomerBalanceService $balances)
    {
        $customer = $this->customer_id ? Customer::find($this->customer_id) : null;

        // Open receivables for the chosen customer (shown at the top).
        $openInvoices = collect();
        $openOpeningBalance = null;
        $outstandingUsd = '0.00';
        $outstandingIls = '0.00';
        $creditUsd = '0.00';
        if ($customer) {
            $openInvoices = Invoice::where('customer_id', $customer->id)
                ->whereIn('status', ['issued', 'sent', 'partially_paid'])
                ->where('remaining_usd', '>', 0)
                ->orderByRaw('due_date is null, due_date asc')->orderBy('invoice_date')
                ->get();
            $openOpeningBalance = CustomerOpeningBalance::where('customer_id', $customer->id)
                ->posted()->debit()->where('remaining_usd', '>', 0)->first();
            $outstandingUsd = $balances->outstandingUsd($customer);
            $outstandingIls = $balances->outstandingIlsByDocument($customer);
            $creditUsd = $balances->unallocatedCreditUsd($customer);
        }

        // Searchable customer picker.
        $customerResults = collect();
        $term = trim($this->customerSearch);
        if ($this->customer_id === null && $term !== '') {
            $customerResults = Customer::query()
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('customer_number', 'like', "%{$term}%")
                    ->orWhere('whatsapp_number', 'like', "%{$term}%"))
                ->orderBy('name')->limit(10)
                ->get(['id', 'name', 'customer_number', 'whatsapp_number']);
        }

        $depositAccounts = FinancialAccount::active()
            ->whereIn('type', [FinancialAccountType::Cash->value, FinancialAccountType::Bank->value])
            ->where('currency', $this->payment_currency)
            ->orderBy('name')->get(['id', 'name', 'type', 'currency']);

        $allocatedTotal = Money::sum(array_map(
            fn ($r) => $r['allocated_usd'] === '' ? '0' : $r['allocated_usd'],
            $this->allocations
        ));

        // Which receivables are currently selected (for the toggle buttons).
        $selectedInvoiceIds = collect($this->allocations)->pluck('invoice_id')->filter()->map(fn ($v) => (int) $v)->all();
        $selectedObIds = collect($this->allocations)->pluck('opening_balance_id')->filter()->map(fn ($v) => (int) $v)->all();

        return view('livewire.admin.payment-create', [
            'currencyOptions' => PaymentCurrency::options(),
            'methodOptions' => PaymentMethod::options(),
            'customerResults' => $customerResults,
            'selectedCustomerName' => $customer?->name,
            'openInvoices' => $openInvoices,
            'openOpeningBalance' => $openOpeningBalance,
            'outstandingUsd' => $outstandingUsd,
            'outstandingIls' => $outstandingIls,
            'creditUsd' => $creditUsd,
            'depositAccounts' => $depositAccounts,
            'usdPreview' => $this->usdPreview,
            'allocatedTotal' => $allocatedTotal,
            'unallocatedPreview' => Money::subtract($this->usdPreview, $allocatedTotal),
            'paymentSummary' => $this->paymentSummary($allocatedTotal),
            'selectedInvoiceIds' => $selectedInvoiceIds,
            'selectedObIds' => $selectedObIds,
        ]);
    }

    /**
     * Pre-post preview (partial / exact / overpayment), derived only from the
     * official values (usdPreview, allocated total, targets' remaining). Mirrors
     * PaymentShow::paymentSummary — display only, no accounting.
     *
     * @return array<string,mixed>
     */
    private function paymentSummary(string $allocatedTotal): array
    {
        $currency = $this->payment_currency;
        $received = Money::money($this->payment_amount ?: 0);
        $paymentUsd = Money::money($this->usdPreview);
        $allocatedUsd = Money::money($allocatedTotal);
        $surplusUsd = Money::money(Money::subtract($paymentUsd, $allocatedUsd));

        $rate = $currency === PaymentCurrency::ILS->value
            ? ((float) ($this->exchange_rate ?? 0) > 0 ? $this->exchange_rate : $this->ilsInvoiceRate())
            : $this->exchange_rate;
        $hasRate = (float) ($rate ?? 0) > 0;

        $allocatedIls = $hasRate ? Money::convertUsdToIls($allocatedUsd, $rate) : null;
        $surplusOriginalIls = ($currency === PaymentCurrency::ILS->value && $allocatedIls !== null)
            ? Money::subtract($received, $allocatedIls)
            : null;

        $targetsRemaining = '0.00';
        foreach ($this->allocations as $row) {
            if (! empty($row['opening_balance_id'])) {
                $t = CustomerOpeningBalance::find($row['opening_balance_id']);
                $targetsRemaining = Money::add($targetsRemaining, $t?->remaining_usd ?? '0');
            } elseif (! empty($row['invoice_id'])) {
                $t = Invoice::find($row['invoice_id']);
                $targetsRemaining = Money::add($targetsRemaining, $t?->remaining_usd ?? '0');
            }
        }
        $remainingAfter = Money::money(max((float) Money::subtract($targetsRemaining, $allocatedUsd), 0));

        $eps = 0.005;
        if ((float) $surplusUsd > $eps) {
            $state = 'overpayment';
        } elseif ((float) $allocatedUsd <= $eps) {
            $state = 'none';
        } elseif ((float) $remainingAfter > $eps) {
            $state = 'partial';
        } else {
            $state = 'exact';
        }

        return [
            'state' => $state,
            'currency' => $currency,
            'symbol' => $currency === PaymentCurrency::ILS->value ? '₪' : '$',
            'received' => $received,
            'allocated_usd' => $allocatedUsd,
            'allocated_ils' => $allocatedIls,
            'surplus_usd' => (float) $surplusUsd < 0 ? '0.00' : $surplusUsd,
            'surplus_original_ils' => $surplusOriginalIls !== null && (float) $surplusOriginalIls > 0 ? $surplusOriginalIls : null,
            'remaining_after_usd' => $remainingAfter,
            'has_rate' => $hasRate,
        ];
    }
}
