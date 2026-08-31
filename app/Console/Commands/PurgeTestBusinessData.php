<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Safely purge trial "business" data (customers, invoices, payments, expenses,
 * customer opening balances) together with EVERY dependent row and the
 * double-entry journal footprint they produced — leaving no orphaned records
 * and no stray accounting movement.
 *
 * It NEVER touches reference/setup data: users, employees, roles, permissions,
 * services, expense categories, chart of accounts, system accounts, financial
 * accounts (and their real opening balances), suppliers, tasks, attendance,
 * leaves, payroll or settings. Tasks that referenced a purged customer keep
 * their history — the FK simply nulls tasks.customer_id.
 *
 * Dry-run by default: it only previews counts. Real deletion requires
 * --execute AND typing PURGE, and runs inside a single transaction so any
 * failure rolls everything back. Idempotent: a second run finds nothing to do.
 */
class PurgeTestBusinessData extends Command
{
    protected $signature = 'app:purge-test-business-data {--execute : Actually delete (default is a dry-run preview)}';

    protected $description = 'Purge trial customers/invoices/payments/expenses and their accounting footprint (dry-run by default).';

    /** Journal source_types produced by the modules being purged. */
    private const JOURNAL_SOURCES = ['invoice', 'payment', 'expense', 'customer_opening_balance'];

    public function handle(): int
    {
        $steps = $this->steps();

        // ---- Preview (always computed first, before any deletion) ----
        $this->info('معاينة بيانات التشغيل التجريبية المرشّحة للحذف:');
        $rows = [];
        $total = 0;
        foreach ($steps as $label => $builder) {
            $count = $builder()->count();
            $total += $count;
            $rows[] = [$label, $count];
        }
        $this->table(['السجل', 'العدد'], $rows);

        $this->line('');
        $this->line('سيبقى محفوظاً: المستخدمون، الموظفون، الأدوار والصلاحيات، الخدمات، تصنيفات المصاريف،');
        $this->line('دليل الحسابات والحسابات النظامية، الحسابات النقدية/البنكية وأرصدتها الافتتاحية،');
        $this->line('الموردون، المهام، الدوام، الإجازات، الرواتب، الإعدادات، وسجل العمليات (Audit).');

        if ($total === 0) {
            $this->info('لا توجد بيانات تجريبية للحذف.');

            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->line('');
            $this->warn('لم يتم حذف أي بيانات. استخدم --execute للتنفيذ الفعلي.');
            $this->line('No data was deleted. Use --execute to continue.');

            return self::SUCCESS;
        }

        // ---- Real deletion (guarded) ----
        $this->line('');
        $this->error('WARNING: This will permanently delete business test data.');
        $answer = $this->ask('اكتب PURGE للمتابعة (Type PURGE to continue)');

        if ($answer !== 'PURGE') {
            $this->warn('تم الإلغاء — لم يُحذف أي شيء. (Aborted.)');

            return self::SUCCESS;
        }

        try {
            $deleted = DB::transaction(function () use ($steps) {
                $out = [];
                foreach ($steps as $label => $builder) {
                    $out[$label] = $builder()->delete();
                }

                return $out;
            });
        } catch (Throwable $e) {
            $this->error('فشل الحذف وتم التراجع الكامل (rolled back): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('تم الحذف بنجاح داخل معاملة واحدة:');
        $this->table(['السجل', 'المحذوف'], array_map(fn ($k, $v) => [$k, $v], array_keys($deleted), array_values($deleted)));
        $this->info('اكتملت عملية التنظيف. أرصدة الصندوق/البنوك تُحتسب من دفتر الأستاذ وتُصحّح تلقائياً.');

        return self::SUCCESS;
    }

    /**
     * Ordered deletion steps (FK-safe: children/lines → entries → documents →
     * customers). Each value is a closure returning a fresh query builder so it
     * can be counted for the preview and executed for the deletion.
     *
     * @return array<string,\Closure():Builder>
     */
    private function steps(): array
    {
        $sourceEntryIds = fn () => DB::table('journal_entries')->whereIn('source_type', self::JOURNAL_SOURCES)->select('id');

        return [
            // 1) Accounting footprint: the journal lines then their entries.
            'بنود القيود المحاسبية (فواتير/دفعات/مصاريف/أرصدة افتتاحية)' => fn () => DB::table('journal_entry_lines')->whereIn('journal_entry_id', $sourceEntryIds()),
            'القيود المحاسبية (فواتير/دفعات/مصاريف/أرصدة افتتاحية)' => fn () => DB::table('journal_entries')->whereIn('source_type', self::JOURNAL_SOURCES),

            // 2) Direct children of the documents.
            'تخصيصات الدفعات' => fn () => DB::table('payment_allocations'),
            'سجلات تذكير الدفع' => fn () => DB::table('payment_reminder_logs'),
            'رسائل واتساب المرتبطة (عميل/فاتورة/دفعة)' => fn () => DB::table('whatsapp_messages')
                ->where(fn ($q) => $q->whereNotNull('customer_id')->orWhereNotNull('invoice_id')->orWhereNotNull('payment_id')),
            'مرفقات العملاء/الفواتير/الدفعات/المصاريف' => fn () => DB::table('attachments')
                ->whereIn('attachable_type', [Customer::class, Invoice::class, Payment::class, Expense::class]),
            'بنود الفواتير' => fn () => DB::table('invoice_items'),
            'الأرصدة الافتتاحية للعملاء' => fn () => DB::table('customer_opening_balances'),

            // 3) Retired-module rows that reference customers (test data only).
            'بنود عروض الأسعار (وحدة متقاعدة)' => fn () => DB::table('quotation_items'),
            'عروض الأسعار (وحدة متقاعدة)' => fn () => DB::table('quotations'),
            'فوترة الاشتراكات (وحدة متقاعدة)' => fn () => DB::table('subscription_billings'),
            'بنود الاشتراكات (وحدة متقاعدة)' => fn () => DB::table('subscription_items'),
            'الاشتراكات (وحدة متقاعدة)' => fn () => DB::table('subscriptions'),

            // 4) The documents themselves.
            'المصاريف' => fn () => DB::table('expenses'),
            'الدفعات والتحصيل' => fn () => DB::table('payments'),
            'الفواتير' => fn () => DB::table('invoices'),
            'المشاريع (وحدة متقاعدة)' => fn () => DB::table('projects'),

            // 5) Finally the customers (tasks.customer_id is nulled by the FK).
            'العملاء' => fn () => DB::table('customers'),
        ];
    }
}
