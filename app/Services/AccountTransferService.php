<?php

namespace App\Services;

use App\Models\AccountTransfer;
use App\Models\FinancialAccount;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Same-currency transfers between cash/bank accounts. Debit destination, credit
 * source. Cross-currency transfers are intentionally out of scope in Sprint 5
 * (they need an explicit FX exchange transaction).
 */
class AccountTransferService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccountingService $accounting,
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
    ) {}

    public function transfer(FinancialAccount $from, FinancialAccount $to, string $amount, ?string $date = null, ?string $notes = null): AccountTransfer
    {
        if ($from->id === $to->id) {
            throw new RuntimeException('لا يمكن التحويل إلى نفس الحساب.');
        }
        if ($from->currency !== $to->currency) {
            throw new RuntimeException('التحويل بين عملات مختلفة غير مدعوم — يتطلب عملية صرف منفصلة.');
        }
        if (Money::isZeroOrNegative($amount)) {
            throw new RuntimeException('قيمة التحويل يجب أن تكون أكبر من صفر.');
        }

        $date ??= now()->toDateString();
        $rate = $from->currency === 'USD' ? Money::rate($this->rates->suggestedRate($date) ?? '1') : '1.000000';
        $amountIls = $from->currency === 'USD' ? Money::convertUsdToIls($amount, $rate) : Money::money($amount);

        return DB::transaction(function () use ($from, $to, $amount, $amountIls, $date, $notes, $rate) {
            $transfer = AccountTransfer::create([
                'transfer_number' => $this->numbers->next('transfer'),
                'transfer_date' => $date,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'currency' => $from->currency,
                'amount' => Money::money($amount),
                'amount_ils' => $amountIls,
                'notes' => $notes,
                'status' => 'posted',
                'posted_at' => now(),
                'created_by' => Auth::id(),
            ]);

            $this->accounting->post([
                'entry_date' => $date,
                'source_type' => 'account_transfer',
                'source_id' => $transfer->id,
                'posting_type' => 'transfer',
                'description' => "تحويل {$transfer->transfer_number}: {$from->name} ← {$to->name}",
            ], [
                [
                    'account_id' => $to->gl_account_id,
                    'description' => 'تحويل وارد',
                    'debit_ils' => $amountIls,
                    'original_currency' => $from->currency,
                    'original_amount' => Money::money($amount),
                    'exchange_rate' => $rate,
                    'financial_account_id' => $to->id,
                ],
                [
                    'account_id' => $from->gl_account_id,
                    'description' => 'تحويل صادر',
                    'credit_ils' => $amountIls,
                    'original_currency' => $from->currency,
                    'original_amount' => Money::money($amount),
                    'exchange_rate' => $rate,
                    'financial_account_id' => $from->id,
                ],
            ]);

            $this->audit->log('account_transfer', $transfer, 'Accounting',
                new: ['amount_ils' => $amountIls], description: "تحويل بين الحسابات {$transfer->transfer_number}");

            return $transfer;
        });
    }
}
