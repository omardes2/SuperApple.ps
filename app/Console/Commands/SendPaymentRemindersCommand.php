<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PaymentReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Sends automatic WhatsApp payment reminders based on the active reminder rules.
 * Registered in the scheduler (daily) and runnable by hand. A rule fires at most
 * once per invoice per due date (enforced by payment_reminder_logs).
 *
 *   php artisan payments:send-reminders
 *   php artisan payments:send-reminders --date=2026-09-01
 *   php artisan payments:send-reminders --dry-run
 */
class SendPaymentRemindersCommand extends Command
{
    protected $signature = 'payments:send-reminders
        {--date= : The date to evaluate rules against (defaults to today)}
        {--dry-run : Report what would be sent without writing anything}';

    protected $description = 'Send automatic WhatsApp payment reminders for due/overdue invoices.';

    public function handle(PaymentReminderService $reminders): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $dry = (bool) $this->option('dry-run');

        Auth::login(User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Accountant', 'General Manager', 'Super Admin']))->first() ?? User::first());

        $this->info($dry ? "معاينة تذكيرات الدفع ليوم {$date}" : "إرسال تذكيرات الدفع ليوم {$date}");

        $result = $reminders->runRules($date, $dry);

        $this->table(['أُرسلت', 'متجاوزة', 'فشل'], [[$result['sent'], $result['skipped'], $result['failed']]]);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
