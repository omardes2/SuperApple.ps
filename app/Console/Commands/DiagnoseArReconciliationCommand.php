<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemAccountKey;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Services\AccountingService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * READ-ONLY diagnosis of the Accounts-Receivable reconciliation gap.
 *
 * For every customer it prints the AR the customer sub-ledger expects (open
 * invoices + opening balances, each valued at its own rate) beside the AR the
 * general ledger actually carries for that customer (Σ posted+reversed AR
 * journal lines), and the per-customer difference — sorted by |difference|.
 * A trailing section lists AR journal lines with NO customer and, crucially,
 * live invoices that are missing their posted issue journal (the fingerprint of
 * the revert-to-draft/re-issue bug). It changes NOTHING.
 */
class DiagnoseArReconciliationCommand extends Command
{
    protected $signature = 'app:diagnose-ar-reconciliation {--limit=0 : Only show the top N customer rows by |difference| (0 = all non-zero)}';

    protected $description = 'Read-only: drill down the AR GL vs customer sub-ledger difference, per customer and per document.';

    public function handle(AccountingService $accounting): int
    {
        $arId = $accounting->systemAccountId(SystemAccountKey::AccountsReceivable);

        // ---- GL AR per customer (posted + reversed net to zero for reversals) ----
        $glByCustomer = JournalEntryLine::where('account_id', $arId)
            ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value]))
            ->selectRaw('customer_id, COALESCE(SUM(debit_ils),0) as d, COALESCE(SUM(credit_ils),0) as c')
            ->groupBy('customer_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->customer_id => Money::subtract($r->d, $r->c)]);

        $glNullCustomer = $glByCustomer->get(0, '0.00'); // customer_id NULL bucket

        // ---- Subsidiary AR per customer ----
        $subByCustomer = [];
        foreach (Invoice::whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->where('remaining_usd', '>', 0)->get() as $inv) {
            $val = Money::convertUsdToIls($inv->remaining_usd, Money::rate($inv->exchange_rate));
            $subByCustomer[$inv->customer_id] = Money::add($subByCustomer[$inv->customer_id] ?? '0.00', $val);
        }
        foreach (CustomerOpeningBalance::posted()->get() as $ob) {
            $rate = Money::rate($ob->exchange_rate);
            $val = $ob->isDebit()
                ? Money::convertUsdToIls($ob->remaining_usd, $rate)
                : Money::subtract('0', Money::convertUsdToIls($ob->amount_usd, $rate));
            $subByCustomer[$ob->customer_id] = Money::add($subByCustomer[$ob->customer_id] ?? '0.00', $val);
        }

        // ---- Merge + per-customer difference (GL − subsidiary, matching the screen) ----
        $customerIds = collect($glByCustomer->keys())
            ->merge(array_keys($subByCustomer))
            ->unique()->filter(fn ($id) => $id > 0)->values();

        $names = Customer::whereIn('id', $customerIds)->get(['id', 'customer_number', 'name'])->keyBy('id');

        $rows = [];
        $sumGl = '0.00';
        $sumSub = '0.00';
        foreach ($customerIds as $id) {
            $gl = $glByCustomer->get($id, '0.00');
            $sub = $subByCustomer[$id] ?? '0.00';
            $diff = Money::subtract($gl, $sub);
            $sumGl = Money::add($sumGl, $gl);
            $sumSub = Money::add($sumSub, $sub);
            if (Money::equals($diff, '0.00')) {
                continue;
            }
            $c = $names->get($id);
            $rows[] = [
                'customer_id' => $id,
                'number' => $c?->customer_number ?? '—',
                'name' => $c?->name ?? '(محذوف)',
                'sub' => $sub,
                'gl' => $gl,
                'diff' => $diff,
            ];
        }

        usort($rows, fn ($a, $b) => (float) Money::absDiff($b['diff'], '0') <=> (float) Money::absDiff($a['diff'], '0'));

        $limit = (int) $this->option('limit');
        $shown = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;

        $this->info('العملاء المسببون للفرق (GL − السجل الفرعي)، مرتبة تنازلياً حسب |الفرق|:');
        $this->table(
            ['#عميل', 'الرقم', 'الاسم', 'السجل الفرعي', 'GL منسوب', 'الفرق'],
            collect($shown)->map(fn ($r) => [
                $r['customer_id'], $r['number'], mb_strimwidth($r['name'], 0, 28, '…'),
                Money::money($r['sub']), Money::money($r['gl']), Money::money($r['diff']),
            ])->all(),
        );

        $sumDiff = Money::subtract($sumGl, $sumSub);
        $this->newLine();
        $this->line('  إجمالي GL منسوب للعملاء : '.Money::money($sumGl).' ILS');
        $this->line('  إجمالي السجل الفرعي     : '.Money::money($sumSub).' ILS');
        $this->line('  قيود AR بلا عميل (GL)   : '.Money::money($glNullCustomer).' ILS');
        $this->line('  ── مجموع فروقات العملاء : '.Money::money($sumDiff).' ILS');
        $this->line('  ── الفرق الكلي (شامل بلا عميل) : '.Money::money(Money::add($sumDiff, $glNullCustomer)).' ILS');

        // ---- Fingerprint: live invoices with no live posted issue journal ----
        $this->newLine();
        $this->info('فواتير حيّة (غير مسودة/ملغاة) بلا قيد إصدار مُرحّل حيّ — بصمة خلل الإصدار المتكرر:');
        $orphans = [];
        foreach (Invoice::whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])->get() as $inv) {
            $hasLiveIssue = $accounting->hasLivePosting('invoice', $inv->id, 'invoice_issue');
            if ($hasLiveIssue) {
                continue;
            }
            $ilsAtIssue = Money::money($inv->total_ils_at_issue ?: Money::convertUsdToIls($inv->total_usd, Money::rate($inv->exchange_rate ?: '0')));
            $orphans[] = [
                $inv->id, $inv->invoice_number, $inv->status->value,
                Money::money($inv->total_usd), $inv->exchange_rate, Money::money($ilsAtIssue),
                Money::money(Money::convertUsdToIls($inv->remaining_usd, Money::rate($inv->exchange_rate ?: '0'))),
            ];
        }

        if ($orphans === []) {
            $this->line('  لا يوجد. (لا فواتير حيّة بلا قيد إصدار)');
        } else {
            $this->table(
                ['#', 'رقم الفاتورة', 'الحالة', 'إجمالي USD', 'سعر الصرف', 'ILS عند الإصدار', 'المتبقي ILS'],
                $orphans,
            );
            $missing = collect($orphans)->reduce(fn ($carry, $r) => Money::add($carry, $r[5]), '0.00');
            $this->line('  إجمالي ILS لقيود الإصدار المفقودة: '.Money::money($missing).' ILS');
            $this->line('  → هذا هو المبلغ الذي سيعيده الإصلاح إلى GL AR.');
        }

        $this->newLine();
        $this->line('<info>تشخيص للقراءة فقط — لم يُعدّل أي شيء.</info>');

        return self::SUCCESS;
    }
}
