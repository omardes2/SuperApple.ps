<?php

namespace App\Services;

use App\Enums\SystemAccountKey;
use App\Models\CustomerOpeningBalance;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Support\Money;
use RuntimeException;

/**
 * Builds the correct double-entry journal for each source document and hands it
 * to the AccountingService. Keeps all "which accounts, which side" accounting
 * knowledge in one place. Every builder is idempotent-friendly (callers check
 * hasPosted) and every journal balances by construction (a plug line derived
 * from the others).
 *
 * Base ledger currency is ILS. USD sources keep their original amount + rate on
 * the relevant line. Exchange differences are realised gain/loss, never revenue.
 */
class LedgerPostingService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly ExchangeRateService $rates,
    ) {}

    // ---------------------------------------------------------------- Invoices

    /**
     * Invoice issue: Debit AR (total ILS), Credit Tax Payable (tax ILS), Credit
     * Service Revenue (balancing plug = AR − tax). Uses the invoice's frozen
     * issue rate. Revenue is the taxable amount after discounts.
     */
    public function postInvoiceIssue(Invoice $invoice): ?JournalEntry
    {
        // Skip only when a LIVE issue journal already exists. An invoice reverted
        // to draft has a reversed issue journal — re-issuing must post a fresh one
        // (otherwise its AR debit is silently lost and AR GL drifts below the
        // sub-ledger). hasLivePosting excludes the reversed original.
        if ($this->accounting->hasLivePosting('invoice', $invoice->id, 'invoice_issue')) {
            return null;
        }

        $rate = Money::rate($invoice->exchange_rate);
        $arIls = Money::money($invoice->total_ils_at_issue);
        $taxIls = Money::convertUsdToIls($invoice->tax_usd, $rate);
        $revenueIls = Money::subtract($arIls, $taxIls); // plug → guarantees balance

        $lines = [[
            'account_id' => $this->accounting->systemAccountId(SystemAccountKey::AccountsReceivable),
            'description' => 'ذمم عميل — فاتورة '.$invoice->invoice_number,
            'debit_ils' => $arIls,
            'original_currency' => 'USD',
            'original_amount' => Money::money($invoice->total_usd),
            'exchange_rate' => $rate,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'project_id' => $invoice->project_id,
        ], [
            'account_id' => $this->accounting->systemAccountId(SystemAccountKey::ServiceRevenue),
            'description' => 'إيراد خدمات — فاتورة '.$invoice->invoice_number,
            'credit_ils' => $revenueIls,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'project_id' => $invoice->project_id,
        ]];

        if (Money::isPositive($taxIls)) {
            $lines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::TaxPayable),
                'description' => 'ضريبة مستحقة — فاتورة '.$invoice->invoice_number,
                'credit_ils' => $taxIls,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
            ];
        }

        return $this->accounting->post([
            'entry_date' => $invoice->issued_at?->toDateString() ?? $invoice->invoice_date->toDateString(),
            'source_type' => 'invoice',
            'source_id' => $invoice->id,
            'posting_type' => 'invoice_issue',
            'description' => 'إصدار فاتورة '.$invoice->invoice_number,
        ], $lines);
    }

    /** Whether a posted issue journal exists for this invoice. */
    public function hasInvoiceJournal(Invoice $invoice): bool
    {
        return $this->accounting->hasPosted('invoice', $invoice->id, 'invoice_issue');
    }

    public function reverseInvoiceIssue(Invoice $invoice, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('posting_type', 'invoice_issue')->first();

        if ($entry === null) {
            return null;
        }

        return $this->accounting->reverse($entry, null, $reason ?? 'إلغاء الفاتورة');
    }

    // ---------------------------------------------------------------- Payments

    /**
     * Customer payment receipt. Debit Cash/Bank (accounting value received),
     * Credit AR per allocation (at each invoice's issue rate), FX gain/loss per
     * allocation, and Credit Customer Credits for any unallocated amount.
     */
    public function postPaymentReceipt(Payment $payment, bool $includeReversed = false): ?JournalEntry
    {
        if ($this->accounting->hasPosted('payment', $payment->id, 'payment_receipt')) {
            return null;
        }

        $paymentRate = Money::rate($payment->exchange_rate);

        // Normally only active allocations; for historical backfill of a
        // cancelled payment we reconstruct from all allocations (then reverse).
        $allocations = $includeReversed
            ? $payment->allocations()->get()
            : $payment->activeAllocations()->get();

        $arCredits = [];
        $gainTotal = '0.00';
        $lossTotal = '0.00';
        $allocatedPaymentValue = '0.00';
        $allocatedUsdTotal = '0.00';

        foreach ($allocations as $alloc) {
            $allocatedUsdTotal = Money::add($allocatedUsdTotal, $alloc->allocated_usd);
            $arCredits[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::AccountsReceivable),
                'description' => $alloc->opening_balance_id !== null
                    ? 'تحصيل ذمم — رصيد افتتاحي #'.$alloc->opening_balance_id
                    : 'تحصيل ذمم — فاتورة #'.$alloc->invoice_id,
                'credit_ils' => Money::money($alloc->invoice_accounting_value_ils),
                'customer_id' => $payment->customer_id,
                'invoice_id' => $alloc->invoice_id,
                'payment_id' => $payment->id,
            ];
            $allocatedPaymentValue = Money::add($allocatedPaymentValue, $alloc->payment_accounting_value_ils);

            $diff = Money::money($alloc->exchange_difference_ils);
            $abs = Money::absDiff($diff, '0');
            if (Money::isPositive($diff)) {
                $gainTotal = Money::add($gainTotal, $abs);
            } elseif (Money::isPositive($abs)) {
                $lossTotal = Money::add($lossTotal, $abs); // diff negative → loss
            }
        }

        // Unallocated amount → customer credit (liability), valued at payment rate.
        $unallocatedUsd = Money::subtract($payment->usd_equivalent, $allocatedUsdTotal);
        $unallocatedIls = Money::convertUsdToIls($unallocatedUsd, $paymentRate);
        if (! Money::isPositive($unallocatedUsd)) {
            $unallocatedIls = '0.00';
        }

        $cashIls = Money::add($allocatedPaymentValue, $unallocatedIls);

        [$cashGlId, $financialAccountId] = $this->resolveCashAccount($payment->account_id, $payment->payment_currency->value);

        $lines = [[
            'account_id' => $cashGlId,
            'description' => 'استلام دفعة '.$payment->payment_number,
            'debit_ils' => $cashIls,
            'original_currency' => $payment->payment_currency->value,
            'original_amount' => Money::money($payment->payment_amount),
            'exchange_rate' => $paymentRate,
            'customer_id' => $payment->customer_id,
            'payment_id' => $payment->id,
            'financial_account_id' => $financialAccountId,
        ]];

        foreach ($arCredits as $line) {
            $lines[] = $line;
        }

        if (Money::isPositive($gainTotal)) {
            $lines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::ExchangeGain),
                'description' => 'ربح فروقات صرف — دفعة '.$payment->payment_number,
                'credit_ils' => $gainTotal,
                'customer_id' => $payment->customer_id,
                'payment_id' => $payment->id,
            ];
        }
        if (Money::isPositive($lossTotal)) {
            $lines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::ExchangeLoss),
                'description' => 'خسارة فروقات صرف — دفعة '.$payment->payment_number,
                'debit_ils' => $lossTotal,
                'customer_id' => $payment->customer_id,
                'payment_id' => $payment->id,
            ];
        }
        if (Money::isPositive($unallocatedIls)) {
            $lines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::CustomerCredits),
                'description' => 'رصيد دائن للعميل — دفعة '.$payment->payment_number,
                'credit_ils' => $unallocatedIls,
                'customer_id' => $payment->customer_id,
                'payment_id' => $payment->id,
            ];
        }

        return $this->accounting->post([
            'entry_date' => $payment->payment_date->toDateString(),
            'source_type' => 'payment',
            'source_id' => $payment->id,
            'posting_type' => 'payment_receipt',
            'description' => 'دفعة عميل '.$payment->payment_number,
        ], $lines);
    }

    public function reversePaymentReceipt(Payment $payment, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'payment')->where('source_id', $payment->id)
            ->where('posting_type', 'payment_receipt')->first();

        if ($entry === null) {
            return null;
        }

        return $this->accounting->reverse($entry, null, $reason ?? 'إلغاء الدفعة');
    }

    // ---------------------------------------------------------------- Expenses

    /**
     * Immediate (paid) expense: Debit expense account, Credit Cash/Bank. Runs at
     * the expense's ILS accounting value. Input tax is not split in Sprint 5.
     */
    public function postExpense(Expense $expense): ?JournalEntry
    {
        if ($this->accounting->hasPosted('expense', $expense->id, 'expense')) {
            return null;
        }

        $expense->loadMissing(['category', 'financialAccount']);

        if ($expense->financial_account_id === null) {
            throw new RuntimeException('يجب تحديد حساب نقدي/بنكي لترحيل المصروف المدفوع.');
        }

        $amountIls = Money::money($expense->amount_ils);
        $expenseAccountId = $expense->category?->default_expense_account_id
            ?? $this->accounting->systemAccountId(SystemAccountKey::DefaultExpense);

        $financialAccount = $expense->financialAccount;
        $this->assertCurrencyMatch($financialAccount, $expense->currency);

        $lines = [[
            'account_id' => $expenseAccountId,
            'description' => $expense->description,
            'debit_ils' => $amountIls,
            'original_currency' => $expense->currency,
            'original_amount' => Money::money($expense->amount),
            'exchange_rate' => $expense->exchange_rate ? Money::rate($expense->exchange_rate) : null,
            'supplier_id' => $expense->supplier_id,
            'project_id' => $expense->project_id,
            'expense_id' => $expense->id,
        ], [
            'account_id' => $financialAccount->gl_account_id,
            'description' => 'دفع مصروف '.$expense->expense_number,
            'credit_ils' => $amountIls,
            'original_currency' => $expense->currency,
            'original_amount' => Money::money($expense->amount),
            'exchange_rate' => $expense->exchange_rate ? Money::rate($expense->exchange_rate) : null,
            'supplier_id' => $expense->supplier_id,
            'project_id' => $expense->project_id,
            'expense_id' => $expense->id,
            'financial_account_id' => $financialAccount->id,
        ]];

        return $this->accounting->post([
            'entry_date' => $expense->expense_date->toDateString(),
            'source_type' => 'expense',
            'source_id' => $expense->id,
            'posting_type' => 'expense',
            'description' => 'مصروف '.$expense->expense_number,
        ], $lines);
    }

    public function reverseExpense(Expense $expense, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'expense')->where('source_id', $expense->id)
            ->where('posting_type', 'expense')->first();

        return $entry ? $this->accounting->reverse($entry, null, $reason ?? 'إلغاء المصروف') : null;
    }

    // ----------------------------------------------------------- Supplier bills

    /**
     * Supplier bill (accrual): Debit expense account(s), Credit Accounts Payable
     * at the bill's ILS accounting value.
     */
    public function postSupplierBill(SupplierBill $bill): ?JournalEntry
    {
        if ($this->accounting->hasPosted('supplier_bill', $bill->id, 'supplier_bill')) {
            return null;
        }

        $bill->loadMissing('items');
        $rate = $bill->exchange_rate ? Money::rate($bill->exchange_rate) : '1.000000';
        $apIls = Money::money($bill->total_ils);

        // Debit expense accounts (grouped by item account, else one default line).
        $expenseLines = [];
        if ($bill->items->isNotEmpty()) {
            $running = '0.00';
            $count = $bill->items->count();
            foreach ($bill->items->values() as $i => $item) {
                $accId = $item->expense_account_id ?? $this->accounting->systemAccountId(SystemAccountKey::DefaultExpense);
                // Per-item ILS at the bill rate; the last item absorbs any
                // rounding residue so the debit side ties exactly to AP.
                if ($i === $count - 1) {
                    $lineIls = Money::subtract($apIls, $running);
                } else {
                    $lineIls = $bill->currency === 'USD'
                        ? Money::convertUsdToIls($item->total, $rate)
                        : Money::money($item->total);
                }
                $running = Money::add($running, $lineIls);
                $expenseLines[] = [
                    'account_id' => $accId,
                    'description' => $item->description,
                    'debit_ils' => $lineIls,
                    'original_currency' => $bill->currency,
                    'original_amount' => Money::money($item->total),
                    'exchange_rate' => $rate,
                    'supplier_id' => $bill->supplier_id,
                    'project_id' => $item->project_id ?? $bill->project_id,
                    'supplier_bill_id' => $bill->id,
                ];
            }
        } else {
            $expenseLines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::DefaultExpense),
                'description' => 'فاتورة مورد '.$bill->bill_number,
                'debit_ils' => $apIls,
                'original_currency' => $bill->currency,
                'original_amount' => Money::money($bill->total),
                'exchange_rate' => $rate,
                'supplier_id' => $bill->supplier_id,
                'project_id' => $bill->project_id,
                'supplier_bill_id' => $bill->id,
            ];
        }

        $lines = $expenseLines;
        $lines[] = [
            'account_id' => $this->accounting->systemAccountId(SystemAccountKey::AccountsPayable),
            'description' => 'ذمم دائنة — '.$bill->bill_number,
            'credit_ils' => $apIls,
            'original_currency' => $bill->currency,
            'original_amount' => Money::money($bill->total),
            'exchange_rate' => $rate,
            'supplier_id' => $bill->supplier_id,
            'supplier_bill_id' => $bill->id,
        ];

        return $this->accounting->post([
            'entry_date' => $bill->bill_date->toDateString(),
            'source_type' => 'supplier_bill',
            'source_id' => $bill->id,
            'posting_type' => 'supplier_bill',
            'description' => 'فاتورة مورد '.$bill->bill_number,
        ], $lines);
    }

    public function reverseSupplierBill(SupplierBill $bill, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'supplier_bill')->where('source_id', $bill->id)
            ->where('posting_type', 'supplier_bill')->first();

        return $entry ? $this->accounting->reverse($entry, null, $reason ?? 'إلغاء فاتورة المورد') : null;
    }

    // -------------------------------------------------------- Supplier payments

    /**
     * Supplier payment: Debit Accounts Payable (at bill rates), FX gain/loss,
     * Credit Cash/Bank (actual paid ILS value).
     */
    public function postSupplierPayment(SupplierPayment $payment): ?JournalEntry
    {
        if ($this->accounting->hasPosted('supplier_payment', $payment->id, 'supplier_payment')) {
            return null;
        }

        $payment->loadMissing(['activeAllocations', 'financialAccount']);
        if ($payment->financial_account_id === null) {
            throw new RuntimeException('يجب تحديد حساب نقدي/بنكي لدفعة المورد.');
        }
        $financialAccount = $payment->financialAccount;
        $this->assertCurrencyMatch($financialAccount, $payment->currency);

        $apDebit = '0.00';
        $cashCredit = '0.00';
        $gainTotal = '0.00';
        $lossTotal = '0.00';

        foreach ($payment->activeAllocations as $alloc) {
            $apDebit = Money::add($apDebit, $alloc->bill_accounting_value_ils);
            $cashCredit = Money::add($cashCredit, $alloc->payment_accounting_value_ils);
            $diff = Money::money($alloc->exchange_difference_ils); // + gain (paid less)
            $abs = Money::absDiff($diff, '0');
            if (Money::isPositive($diff)) {
                $gainTotal = Money::add($gainTotal, $abs);
            } elseif (Money::isPositive($abs)) {
                $lossTotal = Money::add($lossTotal, $abs);
            }
        }

        $lines = [[
            'account_id' => $this->accounting->systemAccountId(SystemAccountKey::AccountsPayable),
            'description' => 'سداد ذمم دائنة — '.$payment->payment_number,
            'debit_ils' => $apDebit,
            'supplier_id' => $payment->supplier_id,
            'supplier_payment_id' => $payment->id,
        ], [
            'account_id' => $financialAccount->gl_account_id,
            'description' => 'دفعة مورد '.$payment->payment_number,
            'credit_ils' => $cashCredit,
            'original_currency' => $payment->currency,
            'original_amount' => Money::money($payment->amount),
            'exchange_rate' => $payment->exchange_rate ? Money::rate($payment->exchange_rate) : null,
            'supplier_id' => $payment->supplier_id,
            'supplier_payment_id' => $payment->id,
            'financial_account_id' => $financialAccount->id,
        ]];

        if (Money::isPositive($gainTotal)) {
            $lines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::ExchangeGain),
                'description' => 'ربح فروقات صرف — دفعة مورد '.$payment->payment_number,
                'credit_ils' => $gainTotal,
                'supplier_id' => $payment->supplier_id,
                'supplier_payment_id' => $payment->id,
            ];
        }
        if (Money::isPositive($lossTotal)) {
            $lines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::ExchangeLoss),
                'description' => 'خسارة فروقات صرف — دفعة مورد '.$payment->payment_number,
                'debit_ils' => $lossTotal,
                'supplier_id' => $payment->supplier_id,
                'supplier_payment_id' => $payment->id,
            ];
        }

        return $this->accounting->post([
            'entry_date' => $payment->payment_date->toDateString(),
            'source_type' => 'supplier_payment',
            'source_id' => $payment->id,
            'posting_type' => 'supplier_payment',
            'description' => 'دفعة مورد '.$payment->payment_number,
        ], $lines);
    }

    public function reverseSupplierPayment(SupplierPayment $payment, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'supplier_payment')->where('source_id', $payment->id)
            ->where('posting_type', 'supplier_payment')->first();

        return $entry ? $this->accounting->reverse($entry, null, $reason ?? 'إلغاء دفعة المورد') : null;
    }

    // ---------------------------------------------------- Opening balance / xfer

    public function postOpeningBalance(FinancialAccount $account): ?JournalEntry
    {
        if ($this->accounting->hasPosted('financial_account', $account->id, 'opening_balance')) {
            return null;
        }
        if (Money::isZeroOrNegative(Money::money($account->opening_balance))) {
            return null;
        }

        $date = $account->opening_balance_date?->toDateString() ?? now()->toDateString();
        $rate = $account->currency === 'USD' ? Money::rate($this->rates->suggestedRate($date) ?? '1') : '1.000000';
        $openingIls = $account->currency === 'USD'
            ? Money::convertUsdToIls($account->opening_balance, $rate)
            : Money::money($account->opening_balance);

        $lines = [[
            'account_id' => $account->gl_account_id,
            'description' => 'رصيد افتتاحي — '.$account->name,
            'debit_ils' => $openingIls,
            'original_currency' => $account->currency,
            'original_amount' => Money::money($account->opening_balance),
            'exchange_rate' => $rate,
            'financial_account_id' => $account->id,
        ], [
            'account_id' => $this->accounting->systemAccountId(SystemAccountKey::OpeningBalanceEquity),
            'description' => 'حقوق ملكية — رصيد افتتاحي '.$account->name,
            'credit_ils' => $openingIls,
        ]];

        return $this->accounting->post([
            'entry_date' => $date,
            'source_type' => 'financial_account',
            'source_id' => $account->id,
            'posting_type' => 'opening_balance',
            'description' => 'رصيد افتتاحي للحساب '.$account->name,
        ], $lines);
    }

    // ----------------------------------------------- Customer opening balances

    /**
     * A customer's pre-system balance. It is NOT a sale — it never touches
     * revenue. A debit balance (customer owes) debits Accounts Receivable and
     * credits Opening Balance Equity; a credit balance (in the customer's
     * favour) is the mirror. ILS is the amount snapshotted at the historical
     * rate; USD stays on the AR line for FX on later collection.
     */
    public function postCustomerOpeningBalance(CustomerOpeningBalance $ob): ?JournalEntry
    {
        if ($this->accounting->hasPosted('customer_opening_balance', $ob->id, 'customer_opening_balance')) {
            return null;
        }

        $arId = $this->accounting->systemAccountId(SystemAccountKey::AccountsReceivable);
        $obeId = $this->accounting->systemAccountId(SystemAccountKey::OpeningBalanceEquity);
        $ils = Money::money($ob->amount_ils);
        $rate = Money::rate($ob->exchange_rate);
        $date = $ob->balance_date->toDateString();

        $arLine = [
            'account_id' => $arId,
            'description' => 'رصيد افتتاحي — '.$ob->customer->name,
            'original_currency' => 'USD',
            'original_amount' => Money::money($ob->amount_usd),
            'exchange_rate' => $rate,
            'customer_id' => $ob->customer_id,
        ];
        $obeLine = [
            'account_id' => $obeId,
            'description' => 'حقوق ملكية — رصيد افتتاحي '.$ob->customer->name,
        ];

        if ($ob->isDebit()) {
            $arLine['debit_ils'] = $ils;   // customer owes → AR up
            $obeLine['credit_ils'] = $ils;
        } else {
            $arLine['credit_ils'] = $ils;  // in customer's favour → AR down
            $obeLine['debit_ils'] = $ils;
        }

        return $this->accounting->post([
            'entry_date' => $date,
            'source_type' => 'customer_opening_balance',
            'source_id' => $ob->id,
            'posting_type' => 'customer_opening_balance',
            'description' => 'رصيد افتتاحي للعميل '.$ob->customer->name,
        ], [$arLine, $obeLine]);
    }

    // ------------------------------------------------------------- Payroll (S6)

    /**
     * Payroll accrual. Dr Salary Expense (earned, i.e. gross − pay reductions);
     * Cr Employee Advances Receivable (recovered advances); Cr other withholding
     * accounts; Cr Salary Payable (net). Balances by construction.
     */
    public function postPayrollRun(PayrollRun $run): ?JournalEntry
    {
        if ($this->accounting->hasPosted('payroll_run', $run->id, 'payroll_run')) {
            return null;
        }

        $run->loadMissing('items');

        $salaryExpense = '0.00';
        $advancesTotal = '0.00';
        $salaryPayable = '0.00';
        $otherByAccount = [];

        foreach ($run->items as $item) {
            $payReductions = Money::sum([$item->absence_deduction_ils, $item->late_deduction_ils, $item->unpaid_leave_deduction_ils]);
            $earned = Money::subtract($item->gross_salary_ils, $payReductions);
            $salaryExpense = Money::add($salaryExpense, $earned);
            $advancesTotal = Money::add($advancesTotal, $item->advances_deduction_ils);
            $salaryPayable = Money::add($salaryPayable, $item->net_salary_ils);

            foreach ((array) ($item->calculation_snapshot['other_withheld_accounts'] ?? []) as $accId => $amount) {
                $otherByAccount[$accId] = Money::add($otherByAccount[$accId] ?? '0.00', $amount);
            }
        }

        $lines = [[
            'account_id' => $this->accounting->systemAccountId(SystemAccountKey::SalaryExpense),
            'description' => 'مصروف رواتب '.$run->periodLabel(),
            'debit_ils' => $salaryExpense,
        ]];

        if (Money::isPositive($advancesTotal)) {
            $lines[] = [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::EmployeeAdvancesReceivable),
                'description' => 'استرداد سلف الموظفين',
                'credit_ils' => $advancesTotal,
            ];
        }

        foreach ($otherByAccount as $accId => $amount) {
            if (! Money::isPositive($amount)) {
                continue;
            }
            $lines[] = [
                'account_id' => $accId > 0 ? (int) $accId : $this->accounting->systemAccountId(SystemAccountKey::PayrollOtherDeductions),
                'description' => 'استقطاعات رواتب',
                'credit_ils' => Money::money($amount),
            ];
        }

        $lines[] = [
            'account_id' => $this->accounting->systemAccountId(SystemAccountKey::SalaryPayable),
            'description' => 'رواتب مستحقة الدفع '.$run->periodLabel(),
            'credit_ils' => $salaryPayable,
        ];

        return $this->accounting->post([
            'entry_date' => $run->period_end->toDateString(),
            'source_type' => 'payroll_run',
            'source_id' => $run->id,
            'posting_type' => 'payroll_run',
            'description' => 'ترحيل رواتب '.$run->periodLabel(),
        ], $lines);
    }

    public function reversePayrollRun(PayrollRun $run, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'payroll_run')->where('source_id', $run->id)
            ->where('posting_type', 'payroll_run')->first();

        return $entry ? $this->accounting->reverse($entry, null, $reason ?? 'عكس مسير الرواتب') : null;
    }

    /** Advance payment: Dr Employee Advances Receivable; Cr Cash/Bank. */
    public function postAdvancePayment(EmployeeAdvance $advance): ?JournalEntry
    {
        if ($this->accounting->hasPosted('employee_advance', $advance->id, 'advance_payment')) {
            return null;
        }
        $account = $advance->financial_account_id ? FinancialAccount::find($advance->financial_account_id) : null;
        if ($account === null) {
            throw new RuntimeException('يجب تحديد حساب نقدي/بنكي لدفع السلفة.');
        }

        $amount = Money::money($advance->amount_ils);

        return $this->accounting->post([
            'entry_date' => ($advance->approved_date ?? $advance->request_date)->toDateString(),
            'source_type' => 'employee_advance',
            'source_id' => $advance->id,
            'posting_type' => 'advance_payment',
            'description' => 'دفع سلفة '.$advance->advance_number,
        ], [
            [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::EmployeeAdvancesReceivable),
                'description' => 'سلفة موظف '.$advance->advance_number,
                'debit_ils' => $amount,
            ],
            [
                'account_id' => $account->gl_account_id,
                'description' => 'دفع سلفة نقداً',
                'credit_ils' => $amount,
                'financial_account_id' => $account->id,
            ],
        ]);
    }

    public function reverseAdvancePayment(EmployeeAdvance $advance, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'employee_advance')->where('source_id', $advance->id)
            ->where('posting_type', 'advance_payment')->first();

        return $entry ? $this->accounting->reverse($entry, null, $reason ?? 'عكس دفع السلفة') : null;
    }

    /** Salary payment: Dr Salary Payable; Cr Cash/Bank. */
    public function postSalaryPayment(PayrollPayment $payment): ?JournalEntry
    {
        if ($this->accounting->hasPosted('payroll_payment', $payment->id, 'salary_payment')) {
            return null;
        }
        $account = FinancialAccount::findOrFail($payment->financial_account_id);
        $amount = Money::money($payment->amount_ils);

        return $this->accounting->post([
            'entry_date' => $payment->payment_date->toDateString(),
            'source_type' => 'payroll_payment',
            'source_id' => $payment->id,
            'posting_type' => 'salary_payment',
            'description' => 'دفع راتب '.$payment->payment_number,
        ], [
            [
                'account_id' => $this->accounting->systemAccountId(SystemAccountKey::SalaryPayable),
                'description' => 'سداد راتب مستحق',
                'debit_ils' => $amount,
            ],
            [
                'account_id' => $account->gl_account_id,
                'description' => 'دفع راتب نقداً',
                'credit_ils' => $amount,
                'financial_account_id' => $account->id,
            ],
        ]);
    }

    public function reverseSalaryPayment(PayrollPayment $payment, ?string $reason = null): ?JournalEntry
    {
        $entry = JournalEntry::posted()
            ->where('source_type', 'payroll_payment')->where('source_id', $payment->id)
            ->where('posting_type', 'salary_payment')->first();

        return $entry ? $this->accounting->reverse($entry, null, $reason ?? 'عكس دفع الراتب') : null;
    }

    /**
     * Resolve the GL cash account + financial-account dimension for a customer
     * payment. Prefers the linked financial account; falls back to the default
     * cash account for the currency (used by historical backfill).
     *
     * @return array{0:int,1:?int}
     */
    private function resolveCashAccount(?int $financialAccountId, string $currency): array
    {
        if ($financialAccountId !== null) {
            $fa = FinancialAccount::find($financialAccountId);
            if ($fa !== null) {
                return [$fa->gl_account_id, $fa->id];
            }
        }

        $key = $currency === 'USD' ? SystemAccountKey::DefaultCashUsd : SystemAccountKey::DefaultCashIls;

        return [$this->accounting->systemAccountId($key), null];
    }

    private function assertCurrencyMatch(FinancialAccount $account, string $currency): void
    {
        if ($account->currency !== $currency) {
            throw new RuntimeException(
                "عدم تطابق العملة: الحساب [{$account->name}] بعملة {$account->currency} والعملية بعملة {$currency}."
            );
        }
    }
}
