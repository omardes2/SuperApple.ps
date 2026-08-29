<?php

namespace App\Livewire\Admin;

use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\QuotationService;
use App\Services\Settings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('عروض الأسعار')]
class QuotationsIndex extends Component
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
        $this->authorize('quotations.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'customer', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Create a blank draft and jump straight into its editor.
     */
    public function create(QuotationService $service)
    {
        $this->authorize('create', Quotation::class);

        $firstCustomer = Customer::active()->orderBy('name')->first() ?? Customer::orderBy('name')->first();
        abort_if($firstCustomer === null, 422);

        $quotation = $service->createDraft([
            'customer_id' => $firstCustomer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays((int) app(Settings::class)->get('finance', 'quotation_validity_days', 14))->toDateString(),
            'terms' => app(Settings::class)->get('finance', 'quotation_terms'),
        ], []);

        return redirect()->route('admin.quotations.show', $quotation);
    }

    public function render()
    {
        $quotations = Quotation::query()
            ->with(['customer', 'project'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('quotation_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->when($this->customer !== '', fn ($q) => $q->where('customer_id', $this->customer))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);

        $stats = [
            'draft' => Quotation::where('status', QuotationStatus::Draft)->count(),
            'sent' => Quotation::where('status', QuotationStatus::Sent)->count(),
            'accepted' => Quotation::where('status', QuotationStatus::Accepted)->count(),
            'expired' => Quotation::where('status', QuotationStatus::Expired)->count(),
        ];

        return view('livewire.admin.quotations-index', [
            'quotations' => $quotations,
            'stats' => $stats,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'statusOptions' => QuotationStatus::options(),
        ]);
    }
}
