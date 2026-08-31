<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Safely purge trial "business" data (customers, invoices, payments, expenses,
 * customer opening balances AND all tasks) together with EVERY dependent row
 * and the double-entry journal footprint they produced — leaving no orphaned
 * records and no stray accounting movement.
 *
 * Tasks are purged with their whole graph: assignees/members, the task↔service
 * pivot (services themselves are kept), comments, checklist items, status
 * history, tags pivot, task attachments, and task-linked notifications. Ad
 * budgets live on the task row and go with it — tasks never touch accounting.
 *
 * It NEVER touches reference/setup data: users, employees, departments, roles,
 * permissions, services, expense categories, chart of accounts, system
 * accounts, financial accounts (and their real opening balances), suppliers,
 * attendance, leaves, payroll, settings, or audit logs.
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
        $this->line('سيبقى محفوظاً: المستخدمون، الموظفون، الأقسام، الأدوار والصلاحيات، الخدمات،');
        $this->line('تصنيفات المصاريف، دليل الحسابات والحسابات النظامية، الحسابات النقدية/البنكية');
        $this->line('وأرصدتها الافتتاحية، الموردون، الدوام، الإجازات، الرواتب، الإعدادات، وسجل العمليات (Audit).');

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

            // 4) Tasks + their whole graph (services themselves are kept; only
            //    the task↔service pivot rows are removed). Children before tasks.
            'أعضاء/إسناد المهام' => fn () => DB::table('task_assignees'),
            'ربط المهام بالخدمات (الخدمات نفسها تبقى)' => fn () => DB::table('task_service'),
            'تعليقات المهام' => fn () => DB::table('task_comments'),
            'عناصر قوائم تحقق المهام' => fn () => DB::table('task_checklist_items'),
            'سجل حالة المهام' => fn () => DB::table('task_status_history'),
            'وسوم المهام' => fn () => DB::table('task_tag'),
            'مرفقات المهام' => fn () => DB::table('attachments')->where('attachable_type', Task::class),
            'إشعارات مرتبطة بالمهام' => fn () => DB::table('notifications')->whereRaw("json_extract(data, '\$.task_id') is not null"),
            'المهام' => fn () => DB::table('tasks'),

            // 5) The financial documents themselves.
            'المصاريف' => fn () => DB::table('expenses'),
            'الدفعات والتحصيل' => fn () => DB::table('payments'),
            'الفواتير' => fn () => DB::table('invoices'),
            'المشاريع (وحدة متقاعدة)' => fn () => DB::table('projects'),

            // 6) Finally the customers.
            'العملاء' => fn () => DB::table('customers'),
        ];
    }
}
