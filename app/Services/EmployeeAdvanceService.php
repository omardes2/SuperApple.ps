<?php

namespace App\Services;

use App\Enums\AdvanceStatus;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRecovery;
use App\Models\PayrollItem;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Employee advances & (interest-free) loans. Paying an advance debits Employee
 * Advances Receivable (an asset), never salary expense. Recovery happens through
 * payroll and reduces that asset. All amounts ILS.
 */
class EmployeeAdvanceService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): EmployeeAdvance
    {
        $advance = EmployeeAdvance::create([
            'advance_number' => $data['advance_number'] ?? $this->numbers->next('advance'),
            'employee_id' => $data['employee_id'],
            'type' => $data['type'] ?? 'advance',
            'request_date' => $data['request_date'] ?? now()->toDateString(),
            'amount_ils' => Money::money($data['amount_ils'] ?? 0),
            'remaining_ils' => Money::money($data['amount_ils'] ?? 0),
            'installment_ils' => isset($data['installment_ils']) && $data['installment_ils'] !== '' ? Money::money($data['installment_ils']) : null,
            'installments' => $data['installments'] ?? null,
            'status' => AdvanceStatus::Draft,
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('advance_created', $advance, 'Payroll', description: 'إنشاء سلفة (مسودة)');

        return $advance;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(EmployeeAdvance $advance, array $data): EmployeeAdvance
    {
        if (! in_array($advance->status, [AdvanceStatus::Draft, AdvanceStatus::Approved], true)) {
            throw new RuntimeException('لا يمكن تعديل سلفة مدفوعة.');
        }

        $advance->update([
            'type' => $data['type'] ?? $advance->type,
            'request_date' => $data['request_date'] ?? $advance->request_date,
            'amount_ils' => Money::money($data['amount_ils'] ?? $advance->amount_ils),
            'remaining_ils' => Money::money($data['amount_ils'] ?? $advance->amount_ils),
            'installment_ils' => array_key_exists('installment_ils', $data) && $data['installment_ils'] !== '' ? Money::money($data['installment_ils']) : $advance->installment_ils,
            'installments' => $data['installments'] ?? $advance->installments,
            'financial_account_id' => $data['financial_account_id'] ?? $advance->financial_account_id,
            'notes' => $data['notes'] ?? $advance->notes,
            'updated_by' => Auth::id(),
        ]);

        return $advance;
    }

    public function approve(EmployeeAdvance $advance, User $actor): EmployeeAdvance
    {
        if (! $advance->isDraft()) {
            throw new RuntimeException('يمكن اعتماد المسودات فقط.');
        }
        $advance->update([
            'status' => AdvanceStatus::Approved,
            'approved_date' => now()->toDateString(),
            'approved_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit->log('advance_approved', $advance, 'Payroll', description: 'اعتماد السلفة');

        return $advance;
    }

    /** Pay the advance to the employee (GL: Dr Employee Advances / Cr Cash). */
    public function pay(EmployeeAdvance $advance): EmployeeAdvance
    {
        if ($advance->status !== AdvanceStatus::Approved) {
            throw new RuntimeException('يجب اعتماد السلفة قبل دفعها.');
        }

        return DB::transaction(function () use ($advance) {
            $advance = EmployeeAdvance::whereKey($advance->id)->lockForUpdate()->firstOrFail();

            if (Money::isZeroOrNegative($advance->amount_ils)) {
                throw new RuntimeException('قيمة السلفة يجب أن تكون أكبر من صفر.');
            }
            if ($advance->financial_account_id === null) {
                throw new RuntimeException('يجب تحديد حساب نقدي/بنكي لدفع السلفة.');
            }

            $advance->update([
                'status' => AdvanceStatus::Paid,
                'remaining_ils' => Money::money($advance->amount_ils),
                'paid_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            $this->ledger->postAdvancePayment($advance->refresh());
            $this->audit->log('advance_paid', $advance, 'Payroll',
                new: ['amount_ils' => $advance->amount_ils], description: "دفع سلفة {$advance->advance_number}");

            return $advance;
        });
    }

    /**
     * Record a recovery against an advance (called during payroll posting).
     * Reduces the outstanding remaining and updates status.
     */
    public function recordRecovery(EmployeeAdvance $advance, PayrollItem $item, string $amount): EmployeeAdvanceRecovery
    {
        $amount = Money::money($amount);
        $newRemaining = Money::subtract($advance->remaining_ils, $amount);
        if (BigDecimal::of($newRemaining)->isNegative()) {
            $newRemaining = '0.00';
        }

        $advance->update([
            'remaining_ils' => $newRemaining,
            'status' => Money::isZeroOrNegative($newRemaining) ? AdvanceStatus::Recovered : AdvanceStatus::PartiallyRecovered,
            'updated_by' => Auth::id(),
        ]);

        $recovery = $advance->recoveries()->create([
            'payroll_run_id' => $item->payroll_run_id,
            'payroll_item_id' => $item->id,
            'amount_ils' => $amount,
            'status' => EmployeeAdvanceRecovery::STATUS_ACTIVE,
        ]);

        $this->audit->log('advance_recovered', $advance, 'Payroll',
            new: ['amount_ils' => $amount, 'remaining' => $newRemaining], description: 'استرداد سلفة عبر الرواتب');

        return $recovery;
    }

    /** Reverse a payroll's advance recoveries (called during payroll reversal). */
    public function reverseRecoveriesForRun(int $payrollRunId): void
    {
        foreach (EmployeeAdvanceRecovery::active()->where('payroll_run_id', $payrollRunId)->get() as $recovery) {
            $advance = $recovery->advance;
            $advance->update([
                'remaining_ils' => Money::add($advance->remaining_ils, $recovery->amount_ils),
                'status' => AdvanceStatus::Paid, // back to outstanding
                'updated_by' => Auth::id(),
            ]);
            $recovery->update(['status' => EmployeeAdvanceRecovery::STATUS_REVERSED]);
        }
    }

    public function cancel(EmployeeAdvance $advance, User $actor, string $reason): EmployeeAdvance
    {
        if ($advance->isCancelled()) {
            throw new RuntimeException('السلفة ملغاة بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }
        if (in_array($advance->status, [AdvanceStatus::PartiallyRecovered, AdvanceStatus::Recovered], true)) {
            throw new RuntimeException('لا يمكن إلغاء سلفة تم استردادها كلياً أو جزئياً.');
        }

        return DB::transaction(function () use ($advance, $actor, $reason) {
            // If it was paid, reverse the payment journal.
            if ($advance->status === AdvanceStatus::Paid) {
                $this->ledger->reverseAdvancePayment($advance, $reason);
            }

            $advance->update([
                'status' => AdvanceStatus::Cancelled,
                'remaining_ils' => '0.00',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            $this->audit->log('advance_cancelled', $advance, 'Payroll',
                new: ['reason' => $reason], description: 'إلغاء السلفة');

            return $advance;
        });
    }
}
