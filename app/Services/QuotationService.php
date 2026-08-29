<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Services\Concerns\BuildsLineItems;
use App\Support\CustomerSnapshot;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QuotationService
{
    use BuildsLineItems;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  iterable<array<string,mixed>>  $lines
     */
    public function createDraft(array $data, iterable $lines = []): Quotation
    {
        return DB::transaction(function () use ($data, $lines) {
            $prepared = $this->prepareItems($lines);

            $quotation = Quotation::create([
                'quotation_number' => $data['quotation_number'] ?? $this->numbers->next('quotation'),
                'customer_id' => $data['customer_id'],
                'project_id' => $data['project_id'] ?? null,
                'quotation_date' => $data['quotation_date'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'currency' => 'USD',
                'status' => QuotationStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'revision_of' => $data['revision_of'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                ...$prepared['totals'],
            ]);

            $this->writeItems($quotation, $prepared['items']);

            $this->audit->log('quotation_created', $quotation, 'Quotations', description: 'إنشاء عرض سعر');

            return $quotation;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  iterable<array<string,mixed>>  $lines
     */
    public function updateDraft(Quotation $quotation, array $data, iterable $lines): Quotation
    {
        $this->assertEditable($quotation);

        return DB::transaction(function () use ($quotation, $data, $lines) {
            $prepared = $this->prepareItems($lines);

            $quotation->update([
                'customer_id' => $data['customer_id'] ?? $quotation->customer_id,
                'project_id' => $data['project_id'] ?? null,
                'quotation_date' => $data['quotation_date'] ?? $quotation->quotation_date,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'updated_by' => Auth::id(),
                ...$prepared['totals'],
            ]);

            $quotation->items()->delete();
            $this->writeItems($quotation, $prepared['items']);

            return $quotation;
        });
    }

    public function send(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw new RuntimeException('يمكن إرسال المسودات فقط.');
        }

        $quotation->update([
            'status' => QuotationStatus::Sent,
            'sent_at' => now(),
            'customer_snapshot' => CustomerSnapshot::for($quotation->customer),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('quotation_sent', $quotation, 'Quotations', description: 'إرسال عرض السعر');

        return $quotation;
    }

    public function accept(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw new RuntimeException('يمكن قبول العروض المرسلة فقط.');
        }

        $quotation->update([
            'status' => QuotationStatus::Accepted,
            'accepted_at' => now(),
            'accepted_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('quotation_accepted', $quotation, 'Quotations', description: 'قبول عرض السعر');

        return $quotation;
    }

    public function reject(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw new RuntimeException('يمكن رفض العروض المرسلة فقط.');
        }

        $quotation->update([
            'status' => QuotationStatus::Rejected,
            'rejected_at' => now(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('quotation_rejected', $quotation, 'Quotations', description: 'رفض عرض السعر');

        return $quotation;
    }

    public function cancel(Quotation $quotation): Quotation
    {
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Sent], true)) {
            throw new RuntimeException('لا يمكن إلغاء هذا العرض في حالته الحالية.');
        }

        $quotation->update([
            'status' => QuotationStatus::Cancelled,
            'cancelled_at' => now(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('quotation_cancelled', $quotation, 'Quotations', description: 'إلغاء عرض السعر');

        return $quotation;
    }

    /**
     * Create a new editable Draft copying the items of a sent/rejected/expired
     * quotation — the way to "edit" a document that has already left Draft.
     */
    public function duplicateAsRevision(Quotation $quotation): Quotation
    {
        return DB::transaction(function () use ($quotation) {
            $lines = $quotation->items->map(fn ($item) => [
                'service_id' => $item->service_id,
                'item_name' => $item->item_name,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_usd' => $item->unit_price_usd,
                'discount_type' => $item->discount_type?->value,
                'discount_value' => $item->discount_value,
                'tax_rate' => $item->tax_rate,
            ])->all();

            $revision = $this->createDraft([
                'customer_id' => $quotation->customer_id,
                'project_id' => $quotation->project_id,
                'quotation_date' => now()->toDateString(),
                'valid_until' => $quotation->valid_until,
                'notes' => $quotation->notes,
                'terms' => $quotation->terms,
                'revision_of' => $quotation->id,
            ], $lines);

            $this->audit->log('quotation_duplicated', $revision, 'Quotations',
                description: "نسخة/مراجعة من {$quotation->quotation_number}");

            return $revision;
        });
    }

    private function assertEditable(Quotation $quotation): void
    {
        if (! $quotation->isEditable()) {
            throw new RuntimeException('لا يمكن تعديل عرض سعر بعد إرساله. أنشئ نسخة/مراجعة جديدة.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function writeItems(Quotation $quotation, array $items): void
    {
        foreach ($items as $item) {
            $quotation->items()->create($item);
        }
    }
}
