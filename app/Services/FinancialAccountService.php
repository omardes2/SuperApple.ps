<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\JournalEntryLine;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cash/bank accounts. The live balance is always DERIVED from posted journal
 * lines tagged with this financial account — never an editable field. Opening
 * balances are posted to the GL against Opening Balance Equity.
 */
class FinancialAccountService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): FinancialAccount
    {
        return DB::transaction(function () use ($data) {
            $account = FinancialAccount::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => $data['currency'],
                'gl_account_id' => $data['gl_account_id'],
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'iban' => $data['iban'] ?? null,
                'opening_balance' => Money::money($data['opening_balance'] ?? 0),
                'opening_balance_date' => $data['opening_balance_date'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $this->ledger->postOpeningBalance($account);
            $this->audit->log('financial_account_created', $account, 'Accounting', description: 'إنشاء حساب نقدي/بنكي');

            return $account;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(FinancialAccount $account, array $data): FinancialAccount
    {
        // Opening balance / currency / GL account are frozen once created (they
        // carry an accounting journal). Only descriptive fields are editable.
        $account->update([
            'name' => $data['name'] ?? $account->name,
            'type' => $data['type'] ?? $account->type,
            'bank_name' => $data['bank_name'] ?? $account->bank_name,
            'account_number' => $data['account_number'] ?? $account->account_number,
            'iban' => $data['iban'] ?? $account->iban,
            'is_active' => $data['is_active'] ?? $account->is_active,
            'notes' => $data['notes'] ?? $account->notes,
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('financial_account_updated', $account, 'Accounting', description: 'تعديل حساب نقدي/بنكي');

        return $account;
    }

    /** Balance in ILS accounting value (Σ debit − Σ credit of posted lines). */
    public function balanceIls(FinancialAccount $account): string
    {
        $row = JournalEntryLine::where('journal_entry_lines.financial_account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))
            ->selectRaw('COALESCE(SUM(debit_ils),0) as d, COALESCE(SUM(credit_ils),0) as c')
            ->first();

        return Money::subtract($row->d ?? 0, $row->c ?? 0);
    }

    /** Balance in the account's own currency (from the line original amounts). */
    public function balanceOriginal(FinancialAccount $account): string
    {
        if ($account->currency !== 'USD') {
            return $this->balanceIls($account);
        }

        $lines = JournalEntryLine::where('journal_entry_lines.financial_account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))
            ->get(['debit_ils', 'credit_ils', 'original_amount']);

        $balance = '0.00';
        foreach ($lines as $line) {
            $amount = $line->original_amount !== null ? Money::money($line->original_amount) : '0.00';
            if (Money::isPositive($line->debit_ils)) {
                $balance = Money::add($balance, $amount);
            } elseif (Money::isPositive($line->credit_ils)) {
                $balance = Money::subtract($balance, $amount);
            }
        }

        return $balance;
    }

    public function hasActivity(FinancialAccount $account): bool
    {
        return JournalEntryLine::where('financial_account_id', $account->id)->exists();
    }
}
