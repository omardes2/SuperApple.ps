<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Services\AccountingReportService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * READ-ONLY breakdown of the dashboard's "إيراد الشهر (محاسبي)" figure.
 *
 * That number is Σ (credit − debit) on every leaf REVENUE account, over journal
 * entries whose status is posted OR reversed and whose entry_date falls in the
 * month — exactly AccountingReportService::profitAndLoss()['total_revenue'].
 * Revenue accrues from invoice ISSUE (Cr Service Revenue) and from FX gains on
 * customer collections (Cr Exchange Gain); payments, cash receipts and customer
 * opening balances never touch a revenue account. This command lists every
 * contributing journal line and proves the total equals the dashboard number.
 * It changes NOTHING.
 */
class DiagnoseMonthlyRevenueCommand extends Command
{
    protected $signature = 'app:diagnose-monthly-revenue {--month= : Month as YYYY-MM (default: current month)}';

    protected $description = 'Read-only: itemise the GL journal lines that form the monthly accounting revenue and tie it to the dashboard.';

    public function handle(AccountingReportService $accounting): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->startOfMonth();
        $from = $month->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $this->info("إيراد الشهر المحاسبي — {$month->format('Y-m')}  ({$from} → {$to})");
        $this->newLine();

        // Leaf revenue accounts (same set the P&L uses).
        $revenueAccounts = Account::where('account_type', AccountType::Revenue->value)
            ->orderBy('code')->get()
            ->reject(fn ($a) => $a->isParent());

        $rows = [];
        $grand = '0.00';
        $perAccount = [];

        foreach ($revenueAccounts as $account) {
            $lines = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($q) => $q
                    ->whereIn('status', ['posted', 'reversed'])
                    ->whereDate('entry_date', '>=', $from)
                    ->whereDate('entry_date', '<=', $to))
                ->with('journalEntry')
                ->get();

            foreach ($lines as $l) {
                $je = $l->journalEntry;
                $net = Money::subtract($l->credit_ils, $l->debit_ils); // revenue is credit-positive
                $invoiceId = $l->invoice_id ?? ($je->source_type === 'invoice' ? $je->source_id : null);
                $invoiceNo = $invoiceId ? optional(Invoice::find($invoiceId))->invoice_number : null;
                $customerName = $l->customer_id ? optional(Customer::find($l->customer_id))->name : null;
                $rows[] = [
                    'date' => Carbon::parse($je->entry_date)->format('Y-m-d'),
                    'journal' => $je->journal_number,
                    'posting_type' => $je->posting_type.($je->status->value === 'reversed' ? ' (معكوس)' : ''),
                    'invoice' => $invoiceNo ?? '—',
                    'customer' => $customerName ?? '—',
                    'account' => $account->code.' '.$account->name,
                    'debit' => Money::money($l->debit_ils),
                    'credit' => Money::money($l->credit_ils),
                    'net' => $net,
                ];
                $grand = Money::add($grand, $net);
                $perAccount[$account->code] = Money::add($perAccount[$account->code] ?? '0.00', $net);
            }
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['journal']] <=> [$b['date'], $b['journal']]);

        if ($rows === []) {
            $this->warn('لا توجد قيود إيراد في هذا الشهر.');
        } else {
            $this->table(
                ['التاريخ', 'رقم القيد', 'نوع القيد', 'الفاتورة', 'العميل', 'حساب الإيراد', 'مدين', 'دائن', 'صافي الإيراد'],
                collect($rows)->map(fn ($r) => [
                    $r['date'], $r['journal'], $r['posting_type'], $r['invoice'],
                    mb_strimwidth($r['customer'], 0, 22, '…'), $r['account'],
                    $r['debit'], $r['credit'], Money::money($r['net']),
                ])->all(),
            );
        }

        $this->newLine();
        $this->line('  التفصيل حسب الحساب:');
        foreach ($perAccount as $code => $amount) {
            $this->line("   - {$code} : ".Money::money($amount).' ILS');
        }
        $this->line('  ── مجموع إيراد الشهر (هذا التقرير): '.Money::money($grand).' ILS');

        // Tie-out against the exact figure the dashboard shows.
        $pl = $accounting->profitAndLoss($from, $to);
        $dashboard = $pl['total_revenue'];
        $matches = Money::equals($grand, $dashboard);
        $this->line('  ── إيراد الشهر في لوحة التحكم (P&L): '.Money::money($dashboard).' ILS');
        $this->newLine();
        $this->line($matches
            ? '<info>✓ مطابق: التقرير يساوي رقم لوحة التحكم بالضبط.</info>'
            : '<error>✗ غير مطابق — راجع الفرق.</error>');

        $this->newLine();
        $this->line('<info>تشخيص للقراءة فقط — لم يُعدّل أي شيء.</info>');

        return self::SUCCESS;
    }
}
