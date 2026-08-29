<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\SystemAccountKey;
use App\Models\Account;
use App\Models\EmployeeAdvance;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\PayrollItem;
use App\Models\SupplierBill;
use App\Support\Money;

/**
 * Ties the GL control accounts back to their sub-ledgers:
 *   - AR GL  == Σ invoice remaining × invoice rate (accounting value)
 *   - AP GL  == Σ supplier-bill remaining × bill rate
 *   - Cash GL == Σ financial-account derived balances
 * A pass means the ledger and the operational data agree.
 */
class ReconciliationService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly FinancialAccountService $financialAccounts,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function accountsReceivable(): array
    {
        $glAccount = $this->accounting->systemAccount(SystemAccountKey::AccountsReceivable);
        $gl = $this->glDebitBalance($glAccount->id);

        $subLedger = '0.00';
        foreach (Invoice::whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->where('remaining_usd', '>', 0)->get() as $invoice) {
            $subLedger = Money::add($subLedger, Money::convertUsdToIls($invoice->remaining_usd, Money::rate($invoice->exchange_rate)));
        }

        return $this->result('الذمم المدينة (AR)', $gl, $subLedger);
    }

    /**
     * @return array<string,mixed>
     */
    public function accountsPayable(): array
    {
        $glAccount = $this->accounting->systemAccount(SystemAccountKey::AccountsPayable);
        $gl = Money::subtract('0', $this->glDebitBalance($glAccount->id)); // credit-positive

        $subLedger = '0.00';
        foreach (SupplierBill::openPayable()->get() as $bill) {
            $rate = $bill->currency === 'USD' && $bill->exchange_rate ? Money::rate($bill->exchange_rate) : '1';
            $value = $bill->currency === 'USD'
                ? Money::convertUsdToIls($bill->remaining_original, $rate)
                : Money::money($bill->remaining_original);
            $subLedger = Money::add($subLedger, $value);
        }

        return $this->result('الذمم الدائنة (AP)', $gl, $subLedger);
    }

    /**
     * @return array<string,mixed>
     */
    public function cash(): array
    {
        // GL side: all leaf accounts under "Cash & Banks" (code 1100).
        $parent = Account::where('code', '1100')->first();
        $gl = '0.00';
        if ($parent) {
            foreach (Account::where('parent_id', $parent->id)->get() as $leaf) {
                $gl = Money::add($gl, $this->glDebitBalance($leaf->id));
            }
        }

        // Sub-ledger side: Σ financial-account derived balances.
        $subLedger = '0.00';
        foreach (FinancialAccount::all() as $account) {
            $subLedger = Money::add($subLedger, $this->financialAccounts->balanceIls($account));
        }

        return $this->result('النقد والبنوك (Cash)', $gl, $subLedger);
    }

    /**
     * Salary Payable: GL 2400 credit balance == Σ posted payroll items' unpaid
     * remaining (after reversals).
     *
     * @return array<string,mixed>
     */
    public function salaryPayable(): array
    {
        $glAccount = $this->accounting->systemAccount(SystemAccountKey::SalaryPayable);
        $gl = Money::subtract('0', $this->glDebitBalance($glAccount->id)); // credit-positive

        $subLedger = PayrollItem::whereHas('payrollRun', fn ($q) => $q->whereIn('status', ['posted', 'paid']))
            ->sum('remaining_payable_ils');

        return $this->result('رواتب مستحقة الدفع (Salary Payable)', $gl, Money::money($subLedger));
    }

    /**
     * Employee Advances Receivable: GL 1400 debit balance == Σ outstanding
     * advances remaining.
     *
     * @return array<string,mixed>
     */
    public function employeeAdvances(): array
    {
        $glAccount = $this->accounting->systemAccount(SystemAccountKey::EmployeeAdvancesReceivable);
        $gl = $this->glDebitBalance($glAccount->id);

        $subLedger = EmployeeAdvance::whereIn('status', ['paid', 'partially_recovered'])
            ->sum('remaining_ils');

        return $this->result('سلف الموظفين (Employee Advances)', $gl, Money::money($subLedger));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return [
            $this->accountsReceivable(), $this->accountsPayable(), $this->cash(),
            $this->salaryPayable(), $this->employeeAdvances(),
        ];
    }

    private function glDebitBalance(int $accountId): string
    {
        $row = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))
            ->selectRaw('COALESCE(SUM(debit_ils),0) as d, COALESCE(SUM(credit_ils),0) as c')
            ->first();

        return Money::subtract($row->d ?? 0, $row->c ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $label, string $gl, string $subLedger): array
    {
        $difference = Money::subtract($gl, $subLedger);

        return [
            'label' => $label,
            'gl_balance' => Money::money($gl),
            'sub_ledger' => Money::money($subLedger),
            'difference' => $difference,
            'balanced' => Money::equals($gl, $subLedger),
        ];
    }
}
