<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a customer's pre-system balance as a real, posted accounting document
 * (Dr/Cr Accounts Receivable ↔ Opening Balance Equity) — never a fake invoice
 * and never a revenue line. USD is the official amount; the ILS value is frozen
 * at the manually-entered historical rate. Posted balances are immutable: a
 * mistake is corrected by reverse() (a mirror journal), never an edit or delete.
 */
class CustomerOpeningBalanceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * Create and immediately post a customer's opening balance.
     *
     * @param  array<string,mixed>  $data  type, amount_usd, exchange_rate, balance_date, notes
     */
    public function create(Customer $customer, array $data): CustomerOpeningBalance
    {
        $type = $data['type'] ?? CustomerOpeningBalance::TYPE_DEBIT;
        if (! in_array($type, [CustomerOpeningBalance::TYPE_DEBIT, CustomerOpeningBalance::TYPE_CREDIT], true)) {
            throw new RuntimeException('نوع الرصيد غير صالح.');
        }
        $amountUsd = $data['amount_usd'] ?? null;
        $rate = $data['exchange_rate'] ?? null;
        $date = $data['balance_date'] ?? null;

        if ($amountUsd === null || $amountUsd === '' || Money::isZeroOrNegative($amountUsd)) {
            throw new RuntimeException('قيمة الرصيد الافتتاحي يجب أن تكون أكبر من صفر.');
        }
        if ($rate === null || $rate === '' || Money::isZeroOrNegative($rate)) {
            throw new RuntimeException('سعر الصرف مطلوب ويجب أن يكون أكبر من صفر.');
        }
        if (blank($date)) {
            throw new RuntimeException('تاريخ الرصيد الافتتاحي مطلوب.');
        }
        if ($customer->openingBalances()->posted()->exists()) {
            throw new RuntimeException('يوجد رصيد افتتاحي مُرحّل لهذا العميل بالفعل. اعكسه أولاً لإدخال رصيد جديد.');
        }

        return DB::transaction(function () use ($customer, $type, $amountUsd, $rate, $date, $data) {
            $amountIls = Money::convertUsdToIls($amountUsd, $rate);
            $isDebit = $type === CustomerOpeningBalance::TYPE_DEBIT;

            $ob = $customer->openingBalances()->create([
                'balance_date' => $date,
                'type' => $type,
                'amount_usd' => Money::money($amountUsd),
                'exchange_rate' => Money::rate($rate),
                'amount_ils' => $amountIls,
                // Only a debit balance is a collectable receivable.
                'remaining_usd' => $isDebit ? Money::money($amountUsd) : '0.00',
                'paid_usd_equivalent' => '0.00',
                'status' => CustomerOpeningBalance::STATUS_POSTED,
                'notes' => $data['notes'] ?? null,
                'posted_at' => now(),
                'posted_by' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $entry = $this->ledger->postCustomerOpeningBalance($ob);
            $ob->forceFill(['journal_entry_id' => $entry?->id])->save();

            $this->audit->log('customer_opening_balance_created', $ob, 'Customers',
                new: [
                    'type' => $type, 'amount_usd' => $ob->amount_usd, 'exchange_rate' => $ob->exchange_rate,
                    'amount_ils' => $ob->amount_ils, 'balance_date' => (string) $date, 'journal_entry_id' => $entry?->id,
                ],
                description: "رصيد افتتاحي ({$type}) للعميل {$customer->name}: {$ob->amount_usd} USD ≈ {$ob->amount_ils} ILS");

            return $ob;
        });
    }

    /**
     * Reverse a posted opening balance (mirror journal + mark reversed). Never a
     * hard delete. Blocked if payments were already allocated against it.
     */
    public function reverse(CustomerOpeningBalance $ob, User $actor, ?string $reason = null): CustomerOpeningBalance
    {
        if (! $ob->isPosted()) {
            throw new RuntimeException('لا يمكن عكس رصيد افتتاحي غير مُرحّل.');
        }
        if ($ob->allocations()->active()->exists()) {
            throw new RuntimeException('لا يمكن عكس الرصيد الافتتاحي بعد تخصيص دفعات عليه. اعكس الدفعات أولاً.');
        }

        return DB::transaction(function () use ($ob, $actor, $reason) {
            if ($ob->journalEntry) {
                $this->accountingReverse($ob, $actor, $reason);
            }

            $ob->update([
                'status' => CustomerOpeningBalance::STATUS_REVERSED,
                'remaining_usd' => '0.00',
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            $this->audit->log('customer_opening_balance_reversed', $ob, 'Customers',
                old: ['amount_usd' => $ob->amount_usd, 'amount_ils' => $ob->amount_ils],
                description: "عكس الرصيد الافتتاحي للعميل {$ob->customer->name}");

            return $ob;
        });
    }

    private function accountingReverse(CustomerOpeningBalance $ob, User $actor, ?string $reason): void
    {
        app(AccountingService::class)->reverse($ob->journalEntry, $actor, $reason ?? 'عكس رصيد افتتاحي');
    }
}
