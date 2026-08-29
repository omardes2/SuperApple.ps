<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lifecycle for expenses: draft → approved → posted (GL) → cancelled (reversal).
 * ILS expenses value 1:1; USD expenses require a rate and value at amount × rate.
 * Posting an expense requires a cash/bank account (an expense is paid; an unpaid
 * purchase is a supplier bill).
 */
class ExpenseService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function createDraft(array $data): Expense
    {
        $currency = $data['currency'] ?? 'ILS';
        $amount = $data['amount'] ?? 0;
        $rate = $data['exchange_rate'] ?? null;

        $expense = Expense::create([
            'expense_number' => $data['expense_number'] ?? $this->numbers->next('expense'),
            'expense_date' => $data['expense_date'] ?? now()->toDateString(),
            'category_id' => $data['category_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'description' => $data['description'] ?? '',
            'currency' => $currency,
            'amount' => Money::money($amount),
            'exchange_rate' => $rate !== null && $rate !== '' ? Money::rate($rate) : null,
            'amount_ils' => $this->amountIls($currency, $amount, $rate),
            'payment_method' => $data['payment_method'] ?? 'cash',
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'tax_amount' => isset($data['tax_amount']) && $data['tax_amount'] !== '' ? Money::money($data['tax_amount']) : null,
            'notes' => $data['notes'] ?? null,
            'status' => ExpenseStatus::Draft,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('expense_created', $expense, 'Expenses', description: 'إنشاء مصروف (مسودة)');

        return $expense;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function updateDraft(Expense $expense, array $data): Expense
    {
        if ($expense->isPosted() || $expense->isCancelled()) {
            throw new RuntimeException('لا يمكن تعديل مصروف مُرحّل أو ملغى.');
        }

        $currency = $data['currency'] ?? $expense->currency;
        $amount = $data['amount'] ?? $expense->amount;
        $rate = array_key_exists('exchange_rate', $data) ? $data['exchange_rate'] : $expense->exchange_rate;

        $expense->update([
            'expense_date' => $data['expense_date'] ?? $expense->expense_date,
            'category_id' => $data['category_id'] ?? $expense->category_id,
            'supplier_id' => $data['supplier_id'] ?? $expense->supplier_id,
            'project_id' => $data['project_id'] ?? $expense->project_id,
            'employee_id' => $data['employee_id'] ?? $expense->employee_id,
            'description' => $data['description'] ?? $expense->description,
            'currency' => $currency,
            'amount' => Money::money($amount),
            'exchange_rate' => $rate !== null && $rate !== '' ? Money::rate($rate) : null,
            'amount_ils' => $this->amountIls($currency, $amount, $rate),
            'payment_method' => $data['payment_method'] ?? $expense->payment_method,
            'financial_account_id' => $data['financial_account_id'] ?? $expense->financial_account_id,
            'reference_number' => $data['reference_number'] ?? $expense->reference_number,
            'tax_amount' => array_key_exists('tax_amount', $data) && $data['tax_amount'] !== '' ? Money::money($data['tax_amount']) : $expense->tax_amount,
            'notes' => $data['notes'] ?? $expense->notes,
            'updated_by' => Auth::id(),
        ]);

        return $expense;
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        if (! $expense->isDraft()) {
            throw new RuntimeException('يمكن اعتماد المسودات فقط.');
        }
        $expense->update(['status' => ExpenseStatus::Approved, 'approved_by' => $actor->id, 'updated_by' => $actor->id]);
        $this->audit->log('expense_approved', $expense, 'Expenses', description: 'اعتماد المصروف');

        return $expense;
    }

    public function post(Expense $expense): Expense
    {
        if (! in_array($expense->status, [ExpenseStatus::Draft, ExpenseStatus::Approved], true)) {
            throw new RuntimeException('لا يمكن ترحيل هذا المصروف في حالته الحالية.');
        }

        return DB::transaction(function () use ($expense) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (Money::isZeroOrNegative($expense->amount)) {
                throw new RuntimeException('قيمة المصروف يجب أن تكون أكبر من صفر.');
            }
            if ($expense->currency === 'USD' && ($expense->exchange_rate === null || Money::isZeroOrNegative($expense->exchange_rate))) {
                throw new RuntimeException('سعر الصرف مطلوب لمصروف بالدولار.');
            }
            if ($expense->financial_account_id === null) {
                throw new RuntimeException('يجب تحديد حساب نقدي/بنكي لترحيل المصروف.');
            }

            $expense->update([
                'amount_ils' => $this->amountIls($expense->currency, $expense->amount, $expense->exchange_rate),
                'status' => ExpenseStatus::Posted,
                'posted_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            $this->ledger->postExpense($expense->refresh());

            $this->audit->log('expense_posted', $expense, 'Expenses',
                new: ['amount_ils' => $expense->amount_ils], description: "ترحيل مصروف {$expense->expense_number}");

            return $expense;
        });
    }

    public function cancel(Expense $expense, User $actor, string $reason): Expense
    {
        if ($expense->isCancelled()) {
            throw new RuntimeException('المصروف ملغى بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }

        return DB::transaction(function () use ($expense, $actor, $reason) {
            if ($expense->isPosted()) {
                $this->ledger->reverseExpense($expense, $reason);
            }

            $expense->update([
                'status' => ExpenseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            $this->audit->log('expense_cancelled', $expense, 'Expenses',
                new: ['reason' => $reason], description: 'إلغاء المصروف');

            return $expense;
        });
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
