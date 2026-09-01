<?php

namespace App\Livewire\Admin;

use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\ExportsCsv;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportsService;
use App\Services\WhatsAppService;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الفواتير')]
class InvoicesIndex extends Component
{
    use ExportsCsv, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $customer = '';

    #[Url]
    public string $status = '';

    /**
     * Quick status/payment tab (a derived filter, NOT a domain status). One of:
     * all|draft|unpaid|partial|paid|overdue|cancelled.
     */
    #[Url]
    public string $tab = 'all';

    /** Rows per page (15/25/50). */
    #[Url]
    public int $perPage = 15;

    // ---- WhatsApp send confirmation modal ----
    public bool $showWhatsapp = false;

    public ?int $waInvoiceId = null;

    /** @var array<string,mixed> */
    public array $waPreview = [];

    // ---- Delete confirmation modal ----
    public bool $showDelete = false;

    public ?int $deleteInvoiceId = null;

    public ?string $deleteNumber = null;

    public bool $deleteIsDraft = false;

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
        if (in_array($name, ['search', 'customer', 'status', 'tab', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    /** Switch the quick tab (derived filter) and jump back to the first page. */
    public function selectTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function create(InvoiceService $service)
    {
        $this->authorize('create', Invoice::class);

        // No default customer: the draft starts customerless and the accountant
        // picks one through the searchable picker on the form (required to issue).
        $invoice = $service->createDraft([
            'customer_id' => null,
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
        $this->deleteIsDraft = $invoice->isDraft();
        $this->showDelete = true;
    }

    public function confirmDelete(InvoiceService $service): void
    {
        $invoice = Invoice::findOrFail($this->deleteInvoiceId);
        $this->authorize('delete', $invoice);

        try {
            $service->delete($invoice);
        } catch (\RuntimeException $e) {
            $this->showDelete = false;
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->showDelete = false;
        session()->flash('status', $this->deleteIsDraft
            ? 'تم حذف مسودة الفاتورة.'
            : 'تم حذف الفاتورة وعكس قيدها المحاسبي بنجاح.');
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

    /**
     * The invoice list under the active filters (search / customer / status
     * select / quick tab). Eager-loads the customer and counts active
     * allocations so the table never issues a query per row. Read-only.
     */
    private function filteredQuery(): Builder
    {
        return Invoice::query()
            ->with(['customer:id,name,customer_number'])
            ->withCount(['activeAllocations as active_allocations_count'])
            ->withCount(['allocations as allocations_count'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('invoice_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('customer_number', 'like', "%{$this->search}%")
                    ->orWhere('whatsapp_number', 'like', "%{$this->search}%"))))
            ->when($this->customer !== '', fn ($q) => $q->where('customer_id', $this->customer))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->tap(fn ($q) => $this->applyTab($q));
    }

    /**
     * Apply the quick tab as a DERIVED filter over existing columns — it never
     * introduces a new domain status. Overdue is computed from due_date exactly
     * like Invoice::isOverdue() / the overdue KPI.
     */
    private function applyTab(Builder $q): Builder
    {
        $today = now()->toDateString();

        return match ($this->tab) {
            'draft' => $q->where('status', InvoiceStatus::Draft->value),
            'unpaid' => $q->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Sent->value])
                ->where('remaining_usd', '>', 0)->where('paid_usd_equivalent', 0),
            'partial' => $q->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
                ->where('paid_usd_equivalent', '>', 0)->where('remaining_usd', '>', 0),
            'paid' => $q->where('status', InvoiceStatus::Paid->value),
            'overdue' => $q->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value, InvoiceStatus::Paid->value])
                ->whereNotNull('due_date')->whereDate('due_date', '<', $today)->where('remaining_usd', '>', 0),
            'cancelled' => $q->where('status', InvoiceStatus::Cancelled->value),
            default => $q,
        };
    }

    /**
     * All tab counts in ONE aggregated query (no per-row / per-tab query).
     *
     * @return array<string,int>
     */
    private function tabCounts(): array
    {
        $today = now()->toDateString();

        $row = Invoice::query()->selectRaw(
            'COUNT(*) as all_count'
            .", SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft"
            .", SUM(CASE WHEN status IN ('issued','sent') AND remaining_usd > 0 AND paid_usd_equivalent = 0 THEN 1 ELSE 0 END) as unpaid"
            .", SUM(CASE WHEN status NOT IN ('draft','cancelled') AND paid_usd_equivalent > 0 AND remaining_usd > 0 THEN 1 ELSE 0 END) as partial"
            .", SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid"
            .", SUM(CASE WHEN status NOT IN ('draft','cancelled','paid') AND due_date IS NOT NULL AND due_date < ? AND remaining_usd > 0 THEN 1 ELSE 0 END) as overdue"
            .", SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled",
            [$today]
        )->first();

        return [
            'all' => (int) $row->all_count,
            'draft' => (int) $row->draft,
            'unpaid' => (int) $row->unpaid,
            'partial' => (int) $row->partial,
            'paid' => (int) $row->paid,
            'overdue' => (int) $row->overdue,
            'cancelled' => (int) $row->cancelled,
        ];
    }

    /**
     * Export the CURRENTLY FILTERED invoices to a CSV (opens in Excel; UTF-8 BOM
     * for Arabic). Never widens visibility — it re-applies the same filters and
     * is gated on reports.export. Read-only: emits stored/derived values only.
     */
    public function export()
    {
        $this->authorize('reports.export');

        $headers = ['رقم الفاتورة', 'التاريخ', 'العميل', 'إجمالي USD', 'مدفوع USD', 'متبقي USD', 'الإجمالي ILS', 'الاستحقاق', 'الحالة'];

        $rows = $this->filteredQuery()->latest()->cursor()->map(function (Invoice $inv) {
            $rate = $inv->exchange_rate;

            return [
                $inv->invoice_number,
                $inv->invoice_date?->format('Y-m-d'),
                $inv->customer?->name ?? '— بلا عميل',
                Format::usd($inv->total_usd),
                Format::usd($inv->paid_usd_equivalent),
                Format::usd($inv->remaining_usd),
                $inv->total_ils_at_issue !== null ? Format::ils($inv->total_ils_at_issue) : '',
                $inv->due_date?->format('Y-m-d') ?? '',
                $inv->effectiveStatus()->label(),
            ];
        });

        return $this->streamCsv('invoices-'.now()->format('Ymd-His').'.csv', $headers, $rows);
    }

    public function render(WhatsAppService $whatsapp)
    {
        $invoices = $this->filteredQuery()->latest()->paginate($this->perPage);

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
            'tabCounts' => $this->tabCounts(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'statusOptions' => InvoiceStatus::options(),
            'whatsappEnabled' => $whatsapp->enabled(),
            'canEdit' => auth()->user()->can('invoices.edit'),
            'canPrint' => auth()->user()->can('invoices.print'),
            'canSend' => auth()->user()->can('invoices.send'),
            'canCancel' => auth()->user()->can('invoices.cancel'),
            'canRecordPayment' => auth()->user()->can('payments.create'),
            'canExport' => auth()->user()->can('reports.export'),
        ]);
    }
}
