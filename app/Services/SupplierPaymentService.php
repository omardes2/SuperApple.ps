<?php

namespace App\Services;

use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\FinancialAccount;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Supplier payments settle vendor bills. AP is reduced at each bill's booked
 * (bill-rate) ILS value; cash leaves at the payment-rate ILS value; the
 * difference on a USD bill is a realised FX gain (paid less) or loss. Payments
 * must be fully allocated to bills of the same currency (no supplier advances
 * or implicit conversion in Sprint 5).
 */
class SupplierPaymentService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function createDraft(array $data): SupplierPayment
    {
        $currency = $data['currency'] ?? 'ILS';
        $amount = $data['amount'] ?? 0;
        $rate = $data['exchange_rate'] ?? null;

        $payment = SupplierPayment::create([
            'payment_number' => $data['payment_number'] ?? $this->numbers->next('supplier_payment'),
            'supplier_id' => $data['supplier_id'],
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'currency' => $currency,
            'amount' => Money::money($amount),
            'exchange_rate' => $rate !== null && $rate !== '' ? Money::rate($rate) : null,
            'amount_ils' => $this->amountIls($currency, $amount, $rate),
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => SupplierPaymentStatus::Draft,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('supplier_payment_created', $payment, 'Suppliers', description: 'إنشاء دفعة مورد (مسودة)');

        return $payment;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function updateDraft(SupplierPayment $payment, array $data): SupplierPayment
    {
        if (! $payment->isDraft()) {
            throw new RuntimeException('لا يمكن تعديل دفعة مورد مُرحّلة.');
        }

        $currency = $data['currency'] ?? $payment->currency;
        $amount = $data['amount'] ?? $payment->amount;
        $rate = array_key_exists('exchange_rate', $data) ? $data['exchange_rate'] : $payment->exchange_rate;

        $payment->update([
            'supplier_id' => $data['supplier_id'] ?? $payment->supplier_id,
            'payment_date' => $data['payment_date'] ?? $payment->payment_date,
            'currency' => $currency,
            'amount' => Money::money($amount),
            'exchange_rate' => $rate !== null && $rate !== '' ? Money::rate($rate) : null,
            'amount_ils' => $this->amountIls($currency, $amount, $rate),
            'financial_account_id' => $data['financial_account_id'] ?? $payment->financial_account_id,
            'reference_number' => $data['reference_number'] ?? $payment->reference_number,
            'notes' => $data['notes'] ?? $payment->notes,
            'updated_by' => Auth::id(),
        ]);

        return $payment;
    }

    /**
     * @param  list<array{bill_id:int,allocated_original:int|string|float}>  $allocations
     */
    public function post(SupplierPayment $payment, array $allocations): SupplierPayment
    {
        if (! $payment->isDraft()) {
            throw new RuntimeException('يمكن ترحيل المسودات فقط.');
        }

        return DB::transaction(function () use ($payment, $allocations) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (Money::isZeroOrNegative($payment->amount)) {
                throw new RuntimeException('قيمة الدفعة يجب أن تكون أكبر من صفر.');
            }
            if ($payment->currency === 'USD' && ($payment->exchange_rate === null || Money::isZeroOrNegative($payment->exchange_rate))) {
                throw new RuntimeException('سعر الصرف مطلوب لدفعة بالدولار.');
            }
            $account = $payment->financial_account_id ? FinancialAccount::find($payment->financial_account_id) : null;
            if ($account === null) {
                throw new RuntimeException('يجب تحديد حساب نقدي/بنكي للدفعة.');
            }
            if ($account->currency !== $payment->currency) {
                throw new RuntimeException('عملة الحساب النقدي يجب أن تطابق عملة الدفعة.');
            }

            $payment->amount_ils = $this->amountIls($payment->currency, $payment->amount, $payment->exchange_rate);

            $totalAllocated = Money::sum(array_map(fn ($a) => $a['allocated_original'], $allocations));
            if (! Money::equals($totalAllocated, $payment->amount)) {
                throw new RuntimeException('يجب تخصيص كامل قيمة الدفعة على فواتير المورد (لا يوجد دفعات مقدمة في هذه المرحلة).');
            }

            foreach ($allocations as $alloc) {
                if (Money::isZeroOrNegative($alloc['allocated_original'])) {
                    continue;
                }
                $this->allocate($payment, (int) $alloc['bill_id'], Money::money($alloc['allocated_original']));
            }

            $payment->status = SupplierPaymentStatus::Posted;
            $payment->posted_at = now();
            $payment->updated_by = Auth::id();
            $payment->save();

            $this->ledger->postSupplierPayment($payment->refresh());

            $this->audit->log('supplier_payment_posted', $payment, 'Suppliers',
                new: ['amount_ils' => $payment->amount_ils], description: "ترحيل دفعة مورد {$payment->payment_number}");

            return $payment;
        });
    }

    public function cancel(SupplierPayment $payment, User $actor, string $reason): SupplierPayment
    {
        if ($payment->isCancelled()) {
            throw new RuntimeException('الدفعة ملغاة بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            foreach ($payment->activeAllocations()->get() as $allocation) {
                $this->reverseAllocation($allocation, $actor, 'إلغاء الدفعة: '.$reason);
            }

            $this->ledger->reverseSupplierPayment($payment, $reason);

            $payment->update([
                'status' => SupplierPaymentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            $this->audit->log('supplier_payment_cancelled', $payment, 'Suppliers',
                new: ['reason' => $reason], description: 'إلغاء وعكس دفعة المورد');

            return $payment;
        });
    }

    private function allocate(SupplierPayment $payment, int $billId, string $allocatedOriginal): SupplierPaymentAllocation
    {
        $bill = SupplierBill::whereKey($billId)->lockForUpdate()->firstOrFail();

        if ((int) $bill->supplier_id !== (int) $payment->supplier_id) {
            throw new RuntimeException('لا يمكن تخصيص دفعة لمورد آخر.');
        }
        if ($bill->currency !== $payment->currency) {
            throw new RuntimeException('عملة الدفعة يجب أن تطابق عملة الفاتورة (لا تحويل ضمني).');
        }
        if (! $bill->acceptsAllocation()) {
            throw new RuntimeException("الفاتورة {$bill->bill_number} لا تقبل تخصيص دفعة.");
        }
        if (Money::isGreaterThan($allocatedOriginal, $bill->remaining_original)) {
            throw new RuntimeException("قيمة التخصيص تتجاوز المتبقي على الفاتورة {$bill->bill_number}.");
        }

        $billRate = $bill->currency === 'USD' && $bill->exchange_rate ? Money::rate($bill->exchange_rate) : '1';
        $paymentRate = $payment->currency === 'USD' && $payment->exchange_rate ? Money::rate($payment->exchange_rate) : '1';

        $billIls = $bill->currency === 'USD' ? Money::convertUsdToIls($allocatedOriginal, $billRate) : Money::money($allocatedOriginal);
        $paymentIls = $payment->currency === 'USD' ? Money::convertUsdToIls($allocatedOriginal, $paymentRate) : Money::money($allocatedOriginal);
        $difference = Money::subtract($billIls, $paymentIls); // + gain (paid less), − loss

        $allocation = $payment->allocations()->create([
            'supplier_bill_id' => $bill->id,
            'allocated_original' => Money::money($allocatedOriginal),
            'bill_accounting_value_ils' => $billIls,
            'payment_accounting_value_ils' => $paymentIls,
            'exchange_difference_ils' => $difference,
            'status' => SupplierPaymentAllocation::STATUS_ACTIVE,
        ]);

        $this->applyToBill($bill, $allocatedOriginal);

        return $allocation;
    }

    private function reverseAllocation(SupplierPaymentAllocation $allocation, User $actor, string $reason): void
    {
        if (! $allocation->isActive()) {
            return;
        }

        $bill = SupplierBill::whereKey($allocation->supplier_bill_id)->lockForUpdate()->firstOrFail();
        $this->applyToBill($bill, '-'.$allocation->allocated_original);

        $allocation->update([
            'status' => SupplierPaymentAllocation::STATUS_REVERSED,
            'reversed_at' => now(),
            'reversed_by' => $actor->id,
            'reversal_reason' => $reason,
        ]);
    }

    private function applyToBill(SupplierBill $bill, string $deltaOriginal): void
    {
        $paid = Money::add($bill->paid_original, $deltaOriginal);
        if (Money::isZeroOrNegative($paid)) {
            $paid = '0.00';
        }
        $remaining = Money::subtract($bill->total, $paid);
        if (BigDecimal::of($remaining)->isNegative()) {
            $remaining = '0.00';
            $paid = Money::money($bill->total);
        }

        $bill->paid_original = $paid;
        $bill->remaining_original = $remaining;
        $bill->status = $this->deriveBillStatus($remaining, $bill->total);
        $bill->save();
    }

    private function deriveBillStatus(string $remaining, string $total): SupplierBillStatus
    {
        if (Money::isZeroOrNegative($remaining)) {
            return SupplierBillStatus::Paid;
        }
        if (Money::isGreaterThan($total, $remaining)) {
            return SupplierBillStatus::PartiallyPaid;
        }

        return SupplierBillStatus::Posted;
    }

    private function amountIls(string $currency, int|string|float $amount, int|string|float|null $rate): string
    {
        if ($currency === 'USD') {
            if ($rate === null || $rate === '' || Money::isZeroOrNegative($rate)) {
                return '0.00';
            }

            return Money::convertUsdToIls($amount, $rate);
        }

        return Money::money($amount);
    }
}
