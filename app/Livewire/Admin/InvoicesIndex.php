<?php

namespace App\Livewire\Admin;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportsService;
use App\Services\WhatsAppService;
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

    // ---- WhatsApp send confirmation modal ----
    public bool $showWhatsapp = false;

    public ?int $waInvoiceId = null;

    /** @var array<string,mixed> */
    public array $waPreview = [];

    // ---- Delete-draft confirmation modal ----
    public bool $showDelete = false;

    public ?int $deleteInvoiceId = null;

    public ?string $deleteNumber = null;

    // ---- Cancel-invoice confirmation modal ----
    public bool $showCancel = false;

    public ?int $cancelInvoiceId = null;

    public ?string $cancelNumber = null;

    public string $cancelReason = '';

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

    // ---------------------------------------------------------------- WhatsApp

    public function openWhatsapp(int $id, WhatsAppService $whatsapp): void
    {
        $invoice = Invoice::with('customer')->findOrFail($id);
        $this->authorize('send', $invoice);

        if ($invoice->isDraft() || $invoice->isCancelled()) {
            session()->flash('error', 'يمكن إرسال الفواتير الصادرة فقط عبر واتساب.');

            return;
        }

        $rate = $invoice->exchange_rate;
        $priorSends = $invoice->whatsappMessages()->count();

        $this->waInvoiceId = $invoice->id;
        $this->waPreview = [
            'customer' => $invoice->customer?->name,
            'phone' => $whatsapp->resolvePhone($invoice->customer) ?? '—',
            'number' => $invoice->invoice_number,
            'total_usd' => Money::money($invoice->total_usd),
            'total_ils' => $rate ? Money::convertUsdToIls($invoice->total_usd, $rate) : null,
            'filename' => 'Invoice-'.$invoice->invoice_number.'.pdf',
            'prior_sends' => $priorSends,
        ];
        $this->showWhatsapp = true;
    }

    public function confirmWhatsapp(WhatsAppService $whatsapp): void
    {
        $invoice = Invoice::with('customer')->findOrFail($this->waInvoiceId);
        $this->authorize('send', $invoice);

        try {
            $whatsapp->sendInvoice($invoice);
        } catch (\Throwable $e) {
            $this->showWhatsapp = false;
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->showWhatsapp = false;
        session()->flash('status', 'تم إرسال الفاتورة عبر واتساب إلى العميل.');
    }

    // --------------------------------------------------------- Record payment

    /**
     * Start recording a payment against a specific invoice: create a draft
     * payment for the invoice's customer and open the payment page with this
     * invoice prefilled. Posting there writes the GL journals (cash + AR),
     * keeping the books correct. Mirrors InvoiceShow::recordPayment.
     */
    public function recordPayment(int $id, PaymentService $service)
    {
        $this->authorize('create', Payment::class);

        $invoice = Invoice::findOrFail($id);
        abort_unless($invoice->acceptsAllocation(), 422, 'الفاتورة لا تقبل تخصيص دفعة.');

        $payment = $service->createDraft([
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_currency' => 'USD',
            'payment_amount' => 0,
        ]);

        return redirect()->route('admin.payments.show', ['payment' => $payment, 'invoice' => $invoice->id]);
    }

    // ------------------------------------------------------------ Edit invoice

    /**
     * Open an invoice for editing. A draft goes straight to its form. An issued
     * or sent invoice is first reverted to draft (its journal reversed, number
     * kept) — the accounting-correct way to edit a posted document — then the
     * form opens. Invoices with active payments cannot be edited until those
     * payments are reversed.
     */
    public function editInvoice(int $id, InvoiceService $service)
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->isDraft()) {
            $this->authorize('update', $invoice);

            return redirect()->route('admin.invoices.show', $invoice);
        }

        $this->authorize('revertToDraft', $invoice);

        try {
            $service->revertToDraft($invoice);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return null;
        }

        session()->flash('status', 'أُعيدت الفاتورة إلى مسودة وعُكس قيدها؛ يمكنك تعديلها ثم إعادة إصدارها.');

        return redirect()->route('admin.invoices.show', $invoice);
    }

    // ------------------------------------------------------------ Delete draft

    public function openDelete(int $id): void
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('delete', $invoice);

        $this->deleteInvoiceId = $invoice->id;
        $this->deleteNumber = $invoice->invoice_number;
        $this->showDelete = true;
    }

    public function confirmDelete(InvoiceService $service): void
    {
        $invoice = Invoice::findOrFail($this->deleteInvoiceId);
        $this->authorize('delete', $invoice);

        try {
            $service->deleteDraft($invoice);
        } catch (\RuntimeException $e) {
            $this->showDelete = false;
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->showDelete = false;
        session()->flash('status', 'تم حذف مسودة الفاتورة.');
    }

    // --------------------------------------------------------- Cancel invoice

    public function openCancel(int $id): void
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('cancel', $invoice);

        $this->cancelInvoiceId = $invoice->id;
        $this->cancelNumber = $invoice->invoice_number;
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function confirmCancel(InvoiceService $service): void
    {
        $invoice = Invoice::findOrFail($this->cancelInvoiceId);
        $this->authorize('cancel', $invoice);

        try {
            $service->cancel($invoice, $this->cancelReason);
        } catch (\RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->showCancel = false;
        session()->flash('status', 'تم إلغاء الفاتورة وعكس قيودها المحاسبية.');
    }

    public function render(WhatsAppService $whatsapp)
    {
        $invoices = Invoice::query()
            ->with(['customer', 'project'])
            ->withCount(['activeAllocations as active_allocations_count'])
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
        // Invoiced-this-month ILS = sum of each invoice's stored accounting value.
        $invoicedThisMonthIls = Invoice::issued()
            ->whereMonth('issued_at', now()->month)->whereYear('issued_at', now()->year)
            ->sum('total_ils_at_issue');

        $overdue = Invoice::issued()
            ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString())
            ->where('remaining_usd', '>', 0)->count();

        $stats = [
            'draft' => Invoice::where('status', InvoiceStatus::Draft)->count(),
            'issued_month' => Invoice::issued()->whereMonth('issued_at', now()->month)->whereYear('issued_at', now()->year)->count(),
            'invoiced_month' => Money::money($invoicedThisMonth),
            'invoiced_month_ils' => Money::money($invoicedThisMonthIls),
            'outstanding' => Money::money($outstanding),
            'outstanding_ils' => app(ReportsService::class)->receivablesIlsByDocument(),
            'overdue' => $overdue,
        ];

        return view('livewire.admin.invoices-index', [
            'invoices' => $invoices,
            'stats' => $stats,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'statusOptions' => InvoiceStatus::options(),
            'whatsappEnabled' => $whatsapp->enabled(),
            'canEdit' => auth()->user()->can('invoices.edit'),
            'canPrint' => auth()->user()->can('invoices.print'),
            'canSend' => auth()->user()->can('invoices.send'),
            'canCancel' => auth()->user()->can('invoices.cancel'),
            'canRecordPayment' => auth()->user()->can('payments.create'),
        ]);
    }
}
