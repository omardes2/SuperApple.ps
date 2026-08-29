<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SubscriptionBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Generates recurring invoices for due subscriptions. Registered in the
 * scheduler (daily) and runnable by hand. Every invoice goes through
 * InvoiceService — this command adds no accounting logic of its own.
 *
 *   php artisan subscriptions:bill                 # bill everything due today
 *   php artisan subscriptions:bill --date=2026-09-01
 *   php artisan subscriptions:bill --dry-run       # write nothing, report only
 *   php artisan subscriptions:bill --subscription=12
 */
class BillSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:bill
        {--date= : The billing date to evaluate (defaults to today)}
        {--dry-run : Report what would be billed without writing anything}
        {--subscription= : Bill only this subscription id}';

    protected $description = 'Generate recurring invoices for due subscriptions (idempotent).';

    public function handle(SubscriptionBillingService $billing): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('subscription') ? (int) $this->option('subscription') : null;

        // Populate created_by on generated invoices with a finance/system user.
        Auth::login(User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Accountant', 'General Manager', 'Super Admin']))->first() ?? User::first());

        $this->info($dry ? "معاينة فوترة الاشتراكات ليوم {$date} (لن تتم أي كتابة)" : "فوترة الاشتراكات المستحقة ليوم {$date}");

        $result = $billing->runDue($date, $dry, $only);

        $this->table(
            ['المعالجة', 'مسودات', 'صادرة', 'متجاوزة', 'فشل'],
            [[$result['processed'], $result['generated'], $result['issued'], $result['skipped'], $result['failed']]],
        );

        foreach ($result['details'] as $d) {
            $line = ($d['subscription_number'] ?? $d['subscription_id']).': '.$d['outcome'].' — '.($d['message'] ?? '');
            $d['outcome'] === 'failed' ? $this->error($line) : $this->line($line);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
