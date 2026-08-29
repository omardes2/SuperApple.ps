<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ManagesDocumentLines;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Service;
use App\Services\QuotationService;
use App\Services\QuotationToInvoiceService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('عرض السعر')]
class QuotationShow extends Component
{
    use ManagesDocumentLines;

    public Quotation $quotation;

    public ?int $customer_id = null;

    public ?int $project_id = null;

    public string $quotation_date = '';

    public ?string $valid_until = null;

    public string $notes = '';

    public string $terms = '';

    public function mount(Quotation $quotation): void
    {
        $this->authorize('view', $quotation);
        $this->quotation = $quotation;
        $this->fillFromModel();
    }

    private function fillFromModel(): void
    {
        $this->quotation->loadMissing('items');
        $this->customer_id = $this->quotation->customer_id;
        $this->project_id = $this->quotation->project_id;
        $this->quotation_date = $this->quotation->quotation_date->toDateString();
        $this->valid_until = $this->quotation->valid_until?->toDateString();
        $this->notes = (string) $this->quotation->notes;
        $this->terms = (string) $this->quotation->terms;
        $this->loadLinesFrom($this->quotation->items);
    }

    public function save(QuotationService $service): void
    {
        $this->authorize('update', $this->quotation);

        $this->validate(array_merge($this->lineRules(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'quotation_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:quotation_date',
        ]));

        $service->updateDraft($this->quotation, [
            'customer_id' => $this->customer_id,
            'project_id' => $this->project_id,
            'quotation_date' => $this->quotation_date,
            'valid_until' => $this->valid_until,
            'notes' => $this->notes,
            'terms' => $this->terms,
        ], $this->lineInputs());

        $this->quotation->refresh();
        $this->fillFromModel();
        session()->flash('status', 'تم حفظ عرض السعر.');
    }

    public function send(QuotationService $service): void
    {
        $this->authorize('send', $this->quotation);
        $this->runAction(fn () => $service->send($this->quotation), 'تم إرسال العرض.');
    }

    public function accept(QuotationService $service): void
    {
        $this->authorize('accept', $this->quotation);
        $this->runAction(fn () => $service->accept($this->quotation), 'تم قبول العرض.');
    }

    public function reject(QuotationService $service): void
    {
        $this->authorize('reject', $this->quotation);
        $this->runAction(fn () => $service->reject($this->quotation), 'تم رفض العرض.');
    }

    public function cancel(QuotationService $service): void
    {
        $this->authorize('cancel', $this->quotation);
        $this->runAction(fn () => $service->cancel($this->quotation), 'تم إلغاء العرض.');
    }

    public function duplicate(QuotationService $service)
    {
        $this->authorize('create', Quotation::class);
        $revision = $service->duplicateAsRevision($this->quotation);

        return redirect()->route('admin.quotations.show', $revision);
    }

    public function convert(QuotationToInvoiceService $service)
    {
        $this->authorize('convert', $this->quotation);

        try {
            $invoice = $service->convert($this->quotation);
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return null;
        }

        return redirect()->route('admin.invoices.show', $invoice);
    }

    private function runAction(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->quotation->refresh();
        $this->fillFromModel();
        session()->flash('status', $message);
    }

    public function render()
    {
        $this->quotation->loadMissing(['customer', 'project', 'items.service', 'invoice']);

        return view('livewire.admin.quotation-show', [
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'services' => Service::active()->orderBy('name')->get(['id', 'name']),
            'preview' => $this->preview(),
            'canEdit' => $this->quotation->isEditable() && auth()->user()->can('quotations.edit'),
        ]);
    }
}
