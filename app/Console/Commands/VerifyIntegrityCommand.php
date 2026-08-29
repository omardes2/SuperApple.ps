<?php

namespace App\Console\Commands;

use App\Services\AccountingReportService;
use App\Services\ReconciliationService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Data-integrity checker. Verifies the invariants that must always hold across
 * the ledger and the operational sub-ledgers, and reports a clear summary.
 *
 * Exit code: FAILURE (1) on any CRITICAL finding (unbalanced accounting,
 * duplicate document number, duplicate billing period, negative balance, or a
 * payment over-allocation); SUCCESS (0) otherwise.
 */
class VerifyIntegrityCommand extends Command
{
    protected $signature = 'app:verify-integrity';

    protected $description = 'Verify data integrity: accounting balance, duplicate documents, billing periods, negative balances, over-allocations.';

    /** @var list<array{check:string,detail:string,ok:bool}> */
    private array $results = [];

    public function handle(): int
    {
        $this->accountingChecks();
        $this->duplicateDocumentChecks();
        $this->duplicateBillingPeriodCheck();
        $this->negativeBalanceChecks();
        $this->overAllocationCheck();

        $this->newLine();
        $this->table(
            ['الفحص', 'النتيجة', 'التفاصيل'],
            collect($this->results)->map(fn ($r) => [
                $r['check'],
                $r['ok'] ? 'سليم' : 'خلل',
                $r['detail'],
            ])->all(),
        );

        $hasFailure = collect($this->results)->contains(fn ($r) => $r['ok'] === false);

        $this->newLine();
        $this->line($hasFailure
            ? '<error>فشل فحص السلامة — توجد أخطاء حرجة في البيانات.</error>'
            : '<info>اجتاز فحص سلامة البيانات.</info>');

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    // ---------------------------------------------------------------- checks

    private function accountingChecks(): void
    {
        try {
            foreach (app(ReconciliationService::class)->all() as $rec) {
                $this->record('مطابقة: '.$rec['label'], $rec['balanced'],
                    $rec['balanced'] ? 'مطابق' : 'فرق '.$rec['difference']);
            }
        } catch (Throwable $e) {
            $this->record('المطابقة المحاسبية', false, 'خطأ: '.$e->getMessage());
        }

        try {
            $tb = app(AccountingReportService::class)->trialBalance();
            $balanced = Money::equals($tb['totals']['ending_debit'], $tb['totals']['ending_credit']);
            $this->record('ميزان المراجعة', $balanced,
                $balanced ? 'متوازن' : 'مدين '.$tb['totals']['ending_debit'].' ≠ دائن '.$tb['totals']['ending_credit']);
        } catch (Throwable $e) {
            $this->record('ميزان المراجعة', false, 'خطأ: '.$e->getMessage());
        }

        // Posted journals whose debit/credit totals do not tie out.
        $unbalanced = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->groupBy('je.id')
            ->havingRaw('ROUND(SUM(jel.debit_ils), 2) <> ROUND(SUM(jel.credit_ils), 2)')
            ->select('je.id')
            ->get()
            ->count();

        $this->record('قيود غير متوازنة (مُرحّلة)', $unbalanced === 0,
            $unbalanced === 0 ? 'لا يوجد' : $unbalanced.' قيد غير متوازن');
    }

    private function duplicateDocumentChecks(): void
    {
        $documents = [
            'أرقام الفواتير' => ['invoices', 'invoice_number'],
            'أرقام الدفعات' => ['payments', 'payment_number'],
            'أرقام الاشتراكات' => ['subscriptions', 'subscription_number'],
            'أرقام عروض الأسعار' => ['quotations', 'quotation_number'],
            'أرقام المصاريف' => ['expenses', 'expense_number'],
        ];

        foreach ($documents as $label => [$table, $column]) {
            $duplicates = DB::table($table)
                ->select($column)
                ->whereNotNull($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();

            $this->record('تكرار: '.$label, $duplicates === 0,
                $duplicates === 0 ? 'لا يوجد' : $duplicates.' رقم مكرّر');
        }
    }

    private function duplicateBillingPeriodCheck(): void
    {
        $duplicates = DB::table('subscription_billings')
            ->select('subscription_id', 'period_start', 'period_end')
            ->groupBy('subscription_id', 'period_start', 'period_end')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $this->record('تكرار فترات الفوترة', $duplicates === 0,
            $duplicates === 0 ? 'لا يوجد' : $duplicates.' فترة مكرّرة');
    }

    private function negativeBalanceChecks(): void
    {
        $balances = [
            'فواتير برصيد سالب' => ['invoices', 'remaining_usd'],
            'فواتير موردين برصيد سالب' => ['supplier_bills', 'remaining_original'],
            'رواتب برصيد سالب' => ['payroll_items', 'remaining_payable_ils'],
            'سلف موظفين برصيد سالب' => ['employee_advances', 'remaining_ils'],
        ];

        foreach ($balances as $label => [$table, $column]) {
            $count = DB::table($table)->where($column, '<', 0)->count();
            $this->record($label, $count === 0, $count === 0 ? 'لا يوجد' : $count.' سجل');
        }
    }

    private function overAllocationCheck(): void
    {
        // A payment whose active allocations exceed the amount actually received.
        $overAllocated = DB::table('payments as p')
            ->leftJoin('payment_allocations as pa', function ($join) {
                $join->on('pa.payment_id', '=', 'p.id')->where('pa.status', '=', 'active');
            })
            ->groupBy('p.id', 'p.usd_equivalent')
            ->havingRaw('ROUND(COALESCE(SUM(pa.allocated_usd), 0), 2) > ROUND(p.usd_equivalent, 2) + 0.005')
            ->select('p.id')
            ->get()
            ->count();

        $this->record('دفعات مخصّصة بزيادة', $overAllocated === 0,
            $overAllocated === 0 ? 'لا يوجد' : $overAllocated.' دفعة');
    }

    // ------------------------------------------------------------- plumbing

    private function record(string $check, bool $ok, string $detail): void
    {
        $this->results[] = ['check' => $check, 'detail' => $detail, 'ok' => $ok];

        $line = "[{$check}] {$detail}";
        $ok ? $this->info($line) : $this->error($line);
    }
}
