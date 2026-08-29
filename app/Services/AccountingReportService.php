<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Read-side accounting reports, all derived from posted journal lines (the GL is
 * the single source of truth). Base currency is ILS throughout.
 */
class AccountingReportService
{
    /**
     * Trial balance for [from, to]. Each account gets opening, period movement
     * and ending, presented as debit/credit columns. Totals must tie out.
     *
     * @return array{rows:list<array<string,mixed>>, totals:array<string,string>}
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $accounts = Account::orderBy('code')->get();
        $rows = [];
        $totalOpeningD = $totalOpeningC = $totalPeriodD = $totalPeriodC = $totalEndD = $totalEndC = '0.00';

        foreach ($accounts as $account) {
            if ($account->isParent()) {
                continue; // postings only hit leaves
            }

            $opening = $from
                ? $this->signedBalance($account->id, null, $this->dayBefore($from))
                : '0.00';
            $period = $this->movement($account->id, $from, $to);
            $ending = Money::add($opening, Money::subtract($period['debit'], $period['credit']));

            // Skip accounts with no opening and no movement.
            if (Money::isZeroOrNegative(Money::absDiff($opening, '0'))
                && Money::isZeroOrNegative($period['debit'])
                && Money::isZeroOrNegative($period['credit'])
                && Money::isZeroOrNegative(Money::absDiff($ending, '0'))) {
                continue;
            }

            [$openD, $openC] = $this->splitSide($opening);
            [$endD, $endC] = $this->splitSide($ending);

            $rows[] = [
                'account' => $account,
                'opening_debit' => $openD, 'opening_credit' => $openC,
                'period_debit' => $period['debit'], 'period_credit' => $period['credit'],
                'ending_debit' => $endD, 'ending_credit' => $endC,
            ];

            $totalOpeningD = Money::add($totalOpeningD, $openD);
            $totalOpeningC = Money::add($totalOpeningC, $openC);
            $totalPeriodD = Money::add($totalPeriodD, $period['debit']);
            $totalPeriodC = Money::add($totalPeriodC, $period['credit']);
            $totalEndD = Money::add($totalEndD, $endD);
            $totalEndC = Money::add($totalEndC, $endC);
        }

        return [
            'rows' => $rows,
            'totals' => [
                'opening_debit' => $totalOpeningD, 'opening_credit' => $totalOpeningC,
                'period_debit' => $totalPeriodD, 'period_credit' => $totalPeriodC,
                'ending_debit' => $totalEndD, 'ending_credit' => $totalEndC,
            ],
        ];
    }

    /**
     * General ledger for one account with a running balance.
     *
     * @return array{opening:string, rows:list<array<string,mixed>>, closing:string}
     */
    public function generalLedger(int $accountId, ?string $from = null, ?string $to = null, array $filters = []): array
    {
        $account = Account::findOrFail($accountId);
        $opening = $from ? $this->signedBalance($accountId, null, $this->dayBefore($from)) : '0.00';

        $query = JournalEntryLine::query()
            ->where('journal_entry_lines.account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->whereIn('status', ['posted', 'reversed']);
                if ($from) {
                    $q->whereDate('entry_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('entry_date', '<=', $to);
                }
            })
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->with('journalEntry')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->select('journal_entry_lines.*');

        $isDebitNormal = $account->normal_balance === NormalBalance::Debit;
        $balance = $opening;
        $rows = [];
        foreach ($query->get() as $line) {
            $delta = $isDebitNormal
                ? Money::subtract($line->debit_ils, $line->credit_ils)
                : Money::subtract($line->credit_ils, $line->debit_ils);
            $balance = Money::add($balance, $delta);
            $rows[] = ['line' => $line, 'entry' => $line->journalEntry, 'balance' => $balance];
        }

        return ['account' => $account, 'opening' => $opening, 'rows' => $rows, 'closing' => $balance];
    }

    /**
     * Profit & Loss for [from, to]. Revenue (credit-normal) less expenses
     * (debit-normal); exchange gain/loss appear as their own lines.
     *
     * @return array<string,mixed>
     */
    public function profitAndLoss(?string $from = null, ?string $to = null): array
    {
        $revenue = [];
        $expenses = [];
        $totalRevenue = '0.00';
        $totalExpense = '0.00';

        foreach (Account::whereIn('account_type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->orderBy('code')->get() as $account) {
            if ($account->isParent()) {
                continue;
            }
            $mv = $this->movement($account->id, $from, $to);
            if ($account->account_type === AccountType::Revenue) {
                $amount = Money::subtract($mv['credit'], $mv['debit']);
                if (! Money::equals($amount, '0')) {
                    $revenue[] = ['account' => $account, 'amount' => $amount];
                    $totalRevenue = Money::add($totalRevenue, $amount);
                }
            } else {
                $amount = Money::subtract($mv['debit'], $mv['credit']);
                if (! Money::equals($amount, '0')) {
                    $expenses[] = ['account' => $account, 'amount' => $amount];
                    $totalExpense = Money::add($totalExpense, $amount);
                }
            }
        }

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_profit' => Money::subtract($totalRevenue, $totalExpense),
        ];
    }

    /**
     * Balance sheet as of a date. Assets = Liabilities + Equity (current-period
     * retained earnings folded into equity).
     *
     * @return array<string,mixed>
     */
    public function balanceSheet(?string $asOf = null): array
    {
        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = $totalLiab = $totalEquity = '0.00';

        foreach (Account::orderBy('code')->get() as $account) {
            if ($account->isParent() || $account->account_type->isNominal()) {
                continue;
            }
            $bal = $this->signedBalance($account->id, null, $asOf); // debit-positive
            if (Money::equals($bal, '0')) {
                continue;
            }
            switch ($account->account_type) {
                case AccountType::Asset:
                    $assets[] = ['account' => $account, 'amount' => $bal];
                    $totalAssets = Money::add($totalAssets, $bal);
                    break;
                case AccountType::Liability:
                    $amt = Money::subtract('0', $bal); // credit-positive
                    $liabilities[] = ['account' => $account, 'amount' => $amt];
                    $totalLiab = Money::add($totalLiab, $amt);
                    break;
                case AccountType::Equity:
                    $amt = Money::subtract('0', $bal);
                    $equity[] = ['account' => $account, 'amount' => $amt];
                    $totalEquity = Money::add($totalEquity, $amt);
                    break;
                default:
                    break;
            }
        }

        // Retained earnings for the period (revenue − expenses up to asOf).
        $pl = $this->profitAndLoss(null, $asOf);
        $retained = $pl['net_profit'];
        $equity[] = ['label' => 'صافي الربح للفترة (أرباح محتجزة)', 'amount' => $retained];
        $totalEquity = Money::add($totalEquity, $retained);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiab,
            'total_equity' => $totalEquity,
            'total_liabilities_equity' => Money::add($totalLiab, $totalEquity),
            'balanced' => Money::equals($totalAssets, Money::add($totalLiab, $totalEquity)),
        ];
    }

    // ------------------------------------------------------------- primitives

    /** Signed (debit-positive) balance of an account across an optional window. */
    private function signedBalance(int $accountId, ?string $from, ?string $to): string
    {
        $mv = $this->movement($accountId, $from, $to);

        return Money::subtract($mv['debit'], $mv['credit']);
    }

    /**
     * @return array{debit:string,credit:string}
     */
    private function movement(int $accountId, ?string $from, ?string $to): array
    {
        $row = JournalEntryLine::where('journal_entry_lines.account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->whereIn('status', ['posted', 'reversed']);
                if ($from) {
                    $q->whereDate('entry_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('entry_date', '<=', $to);
                }
            })
            ->selectRaw('COALESCE(SUM(debit_ils),0) as d, COALESCE(SUM(credit_ils),0) as c')
            ->first();

        return ['debit' => Money::money($row->d ?? 0), 'credit' => Money::money($row->c ?? 0)];
    }

    /**
     * @return array{0:string,1:string} [debit, credit] presentation of a signed balance
     */
    private function splitSide(string $signed): array
    {
        return BigDecimal::of($signed)->isNegative()
            ? ['0.00', Money::absDiff($signed, '0')]
            : [Money::money($signed), '0.00'];
    }

    private function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }
}
