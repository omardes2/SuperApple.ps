<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\AccountingService;
use App\Services\LedgerPostingService;
use App\Services\ReconciliationService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repair the AR reconciliation gap left by the historical revert-to-draft /
 * re-issue bug: live invoices (issued/sent/partially-paid/paid/overdue) whose
 * `invoice_issue` journal was reversed and never re-posted, so the general
 * ledger is missing their AR debit while the sub-ledger still counts them.
 *
 * The repair posts the MISSING issue journal for each such invoice (the exact
 * entry the re-issue should have created), dated at the invoice's issue date.
 * Any payments already booked against the invoice keep their AR credits, so the
 * net GL AR lands exactly on the sub-ledger value — no balancing plug is ever
 * created. It only ever posts a proper, auditable double-entry journal.
 *
 * DEFAULT = dry-run (prints before / adjustments / after). Pass --execute to
 * actually post. This is safe to re-run: an invoice that already has a live
 * issue journal is skipped.
 */
class RepairArReconciliationCommand extends Command
{
    protected $signature = 'app:repair-ar-reconciliation {--execute : Actually post the missing issue journals (default is a dry-run)}';

    protected $description = 'Repair AR GL vs sub-ledger drift by posting the issue journals missing after revert/re-issue. Dry-run unless --execute.';

    public function handle(
        AccountingService $accounting,
        LedgerPostingService $ledger,
        ReconciliationService $recon,
    ): int {
        $execute = (bool) $this->option('execute');

        $before = $recon->accountsReceivable();
        $this->info('AR قبل الإصلاح:');
        $this->line('  GL='.$before['gl_balance'].'  السجل الفرعي='.$before['sub_ledger'].'  الفرق='.$before['difference'].'  '.($before['balanced'] ? 'مطابق' : 'غير مطابق'));
        $this->newLine();

        // Candidates: live invoices with no live posted issue journal.
        $candidates = [];
        foreach (Invoice::whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->orderBy('id')->get() as $inv) {
            if ($accounting->hasLivePosting('invoice', $inv->id, 'invoice_issue')) {
                continue; // already has a live journal — nothing to repair
            }
            $candidates[] = $inv;
        }

        if ($candidates === []) {
            $this->line('<info>لا توجد فواتير تحتاج إصلاحاً — AR سليم من هذه الناحية.</info>');

            return self::SUCCESS;
        }

        $this->info(count($candidates).' فاتورة تحتاج إعادة ترحيل قيد الإصدار:');
        $planIls = '0.00';
        $rows = [];
        $blocked = [];
        foreach ($candidates as $inv) {
            $ilsAtIssue = Money::money($inv->total_ils_at_issue ?: '0');
            if (Money::isZeroOrNegative($ilsAtIssue)) {
                $blocked[] = [$inv->id, $inv->invoice_number, $inv->status->value, 'قيمة ILS عند الإصدار مفقودة — يتطلب مراجعة يدوية'];

                continue;
            }
            $planIls = Money::add($planIls, $ilsAtIssue);
            $rows[] = [
                $inv->id, $inv->invoice_number, $inv->status->value,
                Money::money($inv->total_usd), $inv->exchange_rate, Money::money($ilsAtIssue),
            ];
        }

        $this->table(['#', 'رقم الفاتورة', 'الحالة', 'إجمالي USD', 'سعر الصرف', 'ILS قيد الإصدار'], $rows);
        $this->line('  إجمالي مدين AR سيُعاد ترحيله: '.Money::money($planIls).' ILS');

        if ($blocked !== []) {
            $this->newLine();
            $this->warn('فواتير لا يمكن إصلاحها تلقائياً (تُترك كما هي):');
            $this->table(['#', 'رقم الفاتورة', 'الحالة', 'السبب'], $blocked);
        }

        if (! $execute) {
            $this->newLine();
            $this->line('<comment>عرض تجريبي (dry-run) — لم يُرحّل أي قيد. أعد التشغيل مع --execute للتنفيذ.</comment>');

            return self::SUCCESS;
        }

        // ---- Execute: post each missing issue journal inside one transaction ----
        $this->newLine();
        $posted = 0;
        try {
            DB::transaction(function () use ($rows, $ledger, &$posted) {
                foreach ($rows as $row) {
                    $inv = Invoice::findOrFail($row[0]);
                    $entry = $ledger->postInvoiceIssue($inv);
                    if ($entry !== null) {
                        $posted++;
                        $this->line('  ✓ '.$inv->invoice_number.' → قيد '.$entry->journal_number);
                    } else {
                        $this->line('  • '.$inv->invoice_number.' → تُخطّي (يوجد قيد حيّ بالفعل)');
                    }
                }
            });
        } catch (Throwable $e) {
            $this->error('فشل الإصلاح وتم التراجع عن كل شيء: '.$e->getMessage());

            return self::FAILURE;
        }

        $after = $recon->accountsReceivable();
        $this->newLine();
        $this->info("تم ترحيل {$posted} قيد إصدار.");
        $this->info('AR بعد الإصلاح:');
        $this->line('  GL='.$after['gl_balance'].'  السجل الفرعي='.$after['sub_ledger'].'  الفرق='.$after['difference'].'  '.($after['balanced'] ? 'مطابق ✓' : 'غير مطابق ✗'));

        return $after['balanced'] ? self::SUCCESS : self::FAILURE;
    }
}
