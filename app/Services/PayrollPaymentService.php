<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\PayrollItem;
use App\Models\PayrollPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pays net salaries after a payroll is posted. Dr Salary Payable / Cr Cash.
 * Supports partial payments; the run becomes Paid only when every item is
 * fully paid. Posted payments are immutable — reversal restores the payable.
 */
class PayrollPaymentService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly LedgerPostingService $ledger,
        private readonly PayrollService $payroll,
        private readonly AuditLogger $audit,
    ) {}

    public function pay(PayrollItem $item, string $amount, int $financialAccountId, ?string $date = null, ?string $reference = null): PayrollPayment
    {
        return DB::transaction(function () use ($item, $amount, $financialAccountId, $date, $reference) {
            $item = PayrollItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $run = $item->payrollRun;

            if (! $run->isPosted()) {
                throw new RuntimeException('يجب ترحيل مسير الرواتب قبل دفع الرواتب.');
            }
            $amount = Money::money($amount);
            if (Money::isZeroOrNegative($amount)) {
                throw new RuntimeException('قيمة الدفعة يجب أن تكون أكبر من صفر.');
            }
            if (Money::isGreaterThan($amount, $item->remaining_payable_ils)) {
                throw new RuntimeException('قيمة الدفعة تتجاوز المتبقي على الموظف.');
            }
            $account = FinancialAccount::find($financialAccountId);
            if ($account === null) {
                throw new RuntimeException('يجب تحديد حساب نقدي/بنكي.');
            }
            if ($account->currency !== 'ILS') {
                throw new RuntimeException('رواتب هذه المرحلة بالشيكل فقط — اختر حساباً بالشيكل.');
            }

            $payment = PayrollPayment::create([
                'payment_number' => $this->numbers->next('payroll_payment'),
                'payroll_run_id' => $run->id,
                'payroll_item_id' => $item->id,
                'employee_id' => $item->employee_id,
                'amount_ils' => $amount,
                'financial_account_id' => $account->id,
                'payment_date' => $date ?? now()->toDateString(),
                'reference' => $reference,
                'status' => 'posted',
                'created_by' => Auth::id(),
            ]);

            $this->ledger->postSalaryPayment($payment->refresh());
            $this->payroll->refreshItemPayment($item->refresh());

            $this->audit->log('salary_payment_posted', $payment, 'Payroll',
                new: ['amount_ils' => $amount], description: "دفع راتب {$item->employee_name_snapshot}");

            return $payment;
        });
    }

    public function reverse(PayrollPayment $payment, User $actor, string $reason): PayrollPayment
    {
        if ($payment->status !== 'posted') {
            throw new RuntimeException('تم عكس هذه الدفعة بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب العكس.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $this->ledger->reverseSalaryPayment($payment, $reason);

            $payment->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
            ]);

            $this->payroll->refreshItemPayment($payment->item->refresh());

            $this->audit->log('salary_payment_reversed', $payment, 'Payroll',
                new: ['reason' => $reason], description: 'عكس دفع الراتب');

            return $payment;
        });
    }
}
