<?php

namespace App\Services;

use App\Enums\SupplierBillStatus;
use App\Models\SupplierBill;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vendor bills (accounts payable). Totals are stored in the bill's original
 * currency plus an ILS accounting value at the bill rate. Posting books AP;
 * cancellation reverses it (blocked while active payments exist).
 */
class SupplierBillService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  list<array<string,mixed>>  $items
     */
    public function createDraft(array $data, array $items = []): SupplierBill
    {
        return DB::transaction(function () use ($data, $items) {
            $currency = $data['currency'] ?? 'ILS';
            $rate = $data['exchange_rate'] ?? null;
            $totals = $this->computeTotals($items, $data);

            $bill = SupplierBill::create([
                'bill_number' => $data['bill_number'] ?? $this->numbers->next('supplier_bill'),
                'supplier_id' => $data['supplier_id'],
                'project_id' => $data['project_id'] ?? null,
                'bill_date' => $data['bill_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'currency' => $currency,
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                'exchange_rate' => $rate !== null && $rate !== '' ? Money::rate($rate) : null,
                'total_ils' => $this->totalIls($currency, $totals['total'], $rate),
                'paid_original' => '0.00',
                'remaining_original' => $totals['total'],
                'status' => SupplierBillStatus::Draft,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $this->writeItems($bill, $items);
            $this->audit->log('supplier_bill_created', $bill, 'Suppliers', description: 'إنشاء فاتورة مورد (مسودة)');

            return $bill;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<array<string,mixed>>  $items
     */
    public function updateDraft(SupplierBill $bill, array $data, array $items): SupplierBill
    {
        if (! $bill->isDraft()) {
            throw new RuntimeException('لا يمكن تعديل فاتورة مورد مُرحّلة.');
        }

        return DB::transaction(function () use ($bill, $data, $items) {
            $currency = $data['currency'] ?? $bill->currency;
            $rate = array_key_exists('exchange_rate', $data) ? $data['exchange_rate'] : $bill->exchange_rate;
            $totals = $this->computeTotals($items, $data);

            $bill->update([
                'supplier_id' => $data['supplier_id'] ?? $bill->supplier_id,
                'project_id' => $data['project_id'] ?? $bill->project_id,
                'bill_date' => $data['bill_date'] ?? $bill->bill_date,
                'due_date' => $data['due_date'] ?? $bill->due_date,
                'currency' => $currency,
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                'exchange_rate' => $rate !== null && $rate !== '' ? Money::rate($rate) : null,
                'total_ils' => $this->totalIls($currency, $totals['total'], $rate),
                'remaining_original' => $totals['total'],
                'reference_number' => $data['reference_number'] ?? $bill->reference_number,
                'notes' => $data['notes'] ?? $bill->notes,
                'updated_by' => Auth::id(),
            ]);

            $bill->items()->delete();
            $this->writeItems($bill, $items);

            return $bill;
        });
    }

    public function post(SupplierBill $bill): SupplierBill
    {
        if (! $bill->isDraft()) {
            throw new RuntimeException('يمكن ترحيل المسودات فقط.');
        }

        return DB::transaction(function () use ($bill) {
            $bill = SupplierBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if (Money::isZeroOrNegative($bill->total)) {
                throw new RuntimeException('إجمالي الفاتورة يجب أن يكون أكبر من صفر.');
            }
            if ($bill->currency === 'USD' && ($bill->exchange_rate === null || Money::isZeroOrNegative($bill->exchange_rate))) {
                throw new RuntimeException('سعر الصرف مطلوب لفاتورة بالدولار.');
            }

            $bill->update([
                'total_ils' => $this->totalIls($bill->currency, $bill->total, $bill->exchange_rate),
                'remaining_original' => $bill->total,
                'paid_original' => '0.00',
                'status' => SupplierBillStatus::Posted,
                'posted_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            $this->ledger->postSupplierBill($bill->refresh());

            $this->audit->log('supplier_bill_posted', $bill, 'Suppliers',
                new: ['total_ils' => $bill->total_ils], description: "ترحيل فاتورة مورد {$bill->bill_number}");

            return $bill;
        });
    }

    public function cancel(SupplierBill $bill, User $actor, string $reason): SupplierBill
    {
        if ($bill->isCancelled()) {
            throw new RuntimeException('الفاتورة ملغاة بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }
        if ($bill->activeAllocations()->exists()) {
            throw new RuntimeException('لا يمكن إلغاء فاتورة لها دفعات مخصصة نشطة. ألغِ الدفعات أولاً.');
        }

        return DB::transaction(function () use ($bill, $actor, $reason) {
            if (! $bill->isDraft()) {
                $this->ledger->reverseSupplierBill($bill, $reason);
            }

            $bill->update([
                'status' => SupplierBillStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            $this->audit->log('supplier_bill_cancelled', $bill, 'Suppliers',
                new: ['reason' => $reason], description: 'إلغاء فاتورة المورد');

            return $bill;
        });
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,mixed>  $data
     * @return array{subtotal:string,tax:string,total:string}
     */
    private function computeTotals(array $items, array $data): array
    {
        if ($items === []) {
            // Header-only bill: use the supplied subtotal/tax/total.
            $subtotal = Money::money($data['subtotal'] ?? $data['total'] ?? 0);
            $tax = Money::money($data['tax'] ?? 0);

            return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => Money::add($subtotal, $tax)];
        }

        $subtotal = '0.00';
        $tax = '0.00';
        foreach ($items as $item) {
            $lineBase = Money::multiply($item['quantity'] ?? 1, $item['unit_price'] ?? 0);
            $subtotal = Money::add($subtotal, $lineBase);
            $tax = Money::add($tax, Money::money($item['tax'] ?? 0));
        }

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => Money::add($subtotal, $tax)];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function writeItems(SupplierBill $bill, array $items): void
    {
        foreach (array_values($items) as $i => $item) {
            $lineBase = Money::multiply($item['quantity'] ?? 1, $item['unit_price'] ?? 0);
            $bill->items()->create([
                'expense_account_id' => $item['expense_account_id'] ?? null,
                'project_id' => $item['project_id'] ?? $bill->project_id,
                'description' => $item['description'] ?? '',
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => Money::money($item['unit_price'] ?? 0),
                'tax' => Money::money($item['tax'] ?? 0),
                'total' => Money::add($lineBase, Money::money($item['tax'] ?? 0)),
                'sort_order' => $i,
            ]);
        }
    }

    private function totalIls(string $currency, int|string|float $total, int|string|float|null $rate): string
    {
        if ($currency === 'USD') {
            if ($rate === null || $rate === '' || Money::isZeroOrNegative($rate)) {
                return '0.00';
            }

            return Money::convertUsdToIls($total, $rate);
        }

        return Money::money($total);
    }
}
