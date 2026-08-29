<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ManagesDocumentLines;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Service;
use App\Services\ExchangeRateService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الفاتورة')]
class InvoiceShow extends Component
{
    use ManagesDocumentLines;

    public Invoice $invoice;

    public ?int $customer_id = null;

    public ?int $project_id = null;

    public string $invoice_date = '';

    public ?string $due_date = null;

    public ?string $exchange_rate = null;

    public string $notes = '';

    public string $terms = '';

    // Cancel modal
    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorize('view', $invoice);
        $this->invoice = $invoice;
        $this->fillFromModel();
    }

    private function fillFromModel(): void
    {
        $this->invoice->loadMissing('items');
        $this->customer_id = $this->invoice->customer_id;
        $this->project_id = $this->invoice->project_id;
        $this->invoice_date = $this->invoice->invoice_date->toDateString();
        $this->due_date = $this->invoice->due_date?->toDateString();
        $this->exchange_rate = $this->invoice->exchange_rate;
        $this->notes = (string) $this->invoice->notes;
        $this->terms = (string) $this->invoice->terms;
        $this->loadLinesFrom($this->invoice->items);
    }

    public function suggestRate(ExchangeRateService $service): void
    {
        $this->authorize('update', $this->invoice);
        $this->exchange_rate = $service->suggestedRate($this->invoice_date ?: now()->toDateString());
    }

    public function save(InvoiceService $service): void
    {
        $this->authorize('update', $this->invoice);

        $this->validate(array_merge($this->lineRules(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'exchange_rate' => 'nullable|numeric|gt:0',
        ]));

        $service->updateDraft($this->invoice, [
            'customer_id' => $this->customer_id,
            'project_id' => $this->project_id,
            'invoice_date' => $this->invoice_date,
            'due_date' => $this->due_date,
            'exchange_rate' => $this->exchange_rate,
            'notes' => $this->notes,
            'terms' => $this->terms,
        ], $this->lineInputs());

        $this->invoice->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم حفظ الفاتورة.');
    }

    public function issue(InvoiceService $service): void
    {
        $this->authorize('issue', $this->invoice);

        try {
            // Persist current draft edits first so issue uses fresh values.
            $service->updateDraft($this->invoice, [
                'customer_id' => $this->customer_id,
                'project_id' => $this->project_id,
                'invoice_date' => $this->invoice_date,
                'due_date' => $this->due_date,
                'exchange_rate' => $this->exchange_rate,
                'notes' => $this->notes,
                'terms' => $this->terms,
            ], $this->lineInputs());

            $service->issue($this->invoice->refresh());
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->invoice->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم إصدار الفاتورة وقفل بياناتها المالية.');
    }

    public function send(InvoiceService $service): void
    {
        $this->authorize('send', $this->invoice);
        $this->runAction(fn () => $service->send($this->invoice), 'تم إرسال الفاتورة.');
    }

    public function openCancel(): void
    {
        $this->authorize('cancel', $this->invoice);
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function confirmCancel(InvoiceService $service): void
    {
        $this->authorize('cancel', $this->invoice);

        try {
            $service->cancel($this->invoice, $this->cancelReason);
        } catch (\RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->showCancel = false;
        $this->invoice->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم إلغاء الفاتورة.');
    }

    /**
     * Start recording a payment against this invoice: create a draft for the
     * invoice's customer and open the payment page with this invoice prefilled.
     */
    public function recordPayment(PaymentService $service)
    {
        $this->authorize('create', Payment::class);
        abort_unless($this->invoice->acceptsAllocation(), 422, 'الفاتورة لا تقبل تخصيص دفعة.');

        $payment = $service->createDraft([
            'customer_id' => $this->invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_currency' => 'USD',
            'payment_amount' => 0,
        ]);

        return redirect()->route('admin.payments.show', ['payment' => $payment, 'invoice' => $this->invoice->id]);
    }

    private function runAction(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->invoice->refresh();
        $this->fillFromModel();
        session()->flash('status', $message);
    }

    public function render()
    {
        $this->invoice->loadMissing(['customer', 'project', 'items.service', 'quotation']);

        $canPayments = auth()->user()->can('payments.view');

        return view('livewire.admin.invoice-show', [
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'services' => Service::active()->orderBy('name')->get(['id', 'name']),
            'preview' => $this->preview(),
            'canEdit' => $this->invoice->isDraft() && auth()->user()->can('invoices.edit'),
            'canPayments' => $canPayments,
            'canRecordPayment' => $this->invoice->acceptsAllocation() && auth()->user()->can('payments.create'),
            'allocations' => $canPayments
                ? $this->invoice->allocations()->with('payment')->latest()->get()
                : collect(),
        ]);
    }
}
