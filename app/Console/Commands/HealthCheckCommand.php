<?php

namespace App\Console\Commands;

use App\Enums\SystemAccountKey;
use App\Models\SystemAccount;
use App\Services\AccountingReportService;
use App\Services\ReconciliationService;
use App\Services\Settings;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production-readiness validator. Each check reports PASS / WARN / FAIL and is
 * also collected into a structured summary (available as JSON via --json).
 *
 * SECURITY: this command never prints secret values (APP_KEY, DB password,
 * WhatsApp tokens, ...). It only ever reports whether a secret is configured.
 *
 * Exit code: FAILURE (1) if any check FAILs, otherwise SUCCESS (0). WARNs do not
 * affect the exit code — they flag things worth attention that are not blockers.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'app:health-check {--json : Output a JSON summary instead of human-readable lines}';

    protected $description = 'Validate production readiness (env, database, cache, queue, accounts, settings, accounting integrity).';

    /** @var list<array{status:string,check:string,message:string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->environmentChecks();
        $this->databaseCheck();
        $this->cacheCheck();
        $this->queueCheck();
        $this->storageCheck();
        $this->systemAccountsCheck();
        $this->permissionCatalogCheck();
        $this->settingsChecks();
        $this->schedulerCheck();
        $this->whatsappCheck();
        $this->migrationsCheck();
        $this->accountingIntegrityChecks();

        $hasFailure = collect($this->results)->contains(fn ($r) => $r['status'] === 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $hasFailure,
                'counts' => [
                    'pass' => collect($this->results)->where('status', 'PASS')->count(),
                    'warn' => collect($this->results)->where('status', 'WARN')->count(),
                    'fail' => collect($this->results)->where('status', 'FAIL')->count(),
                ],
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->newLine();
            $this->line($hasFailure
                ? '<error>فشل فحص الجاهزية — راجع البنود المعلّمة FAIL أعلاه.</error>'
                : '<info>اجتاز فحص الجاهزية (قد توجد تحذيرات WARN غير حاجبة).</info>');
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    // ---------------------------------------------------------------- checks

    private function environmentChecks(): void
    {
        $this->record('PASS', 'app.env', 'APP_ENV = '.config('app.env'));

        config('app.debug')
            ? $this->record('WARN', 'app.debug', 'APP_DEBUG مفعّل (يجب تعطيله في الإنتاج).')
            : $this->record('PASS', 'app.debug', 'APP_DEBUG معطّل.');

        // Never print the key itself — only whether one is configured.
        $keyConfigured = filled(config('app.key'));
        $this->record($keyConfigured ? 'PASS' : 'FAIL', 'app.key',
            $keyConfigured ? 'APP_KEY مُعرّف.' : 'APP_KEY غير مُعرّف.');
    }

    private function databaseCheck(): void
    {
        try {
            DB::connection()->getPdo();
            $this->record('PASS', 'database', 'الاتصال بقاعدة البيانات ناجح ('.config('database.default').').');
        } catch (Throwable $e) {
            // Message may reference host/user but never a password (PDO omits it).
            $this->record('FAIL', 'database', 'تعذّر الاتصال بقاعدة البيانات: '.$e->getMessage());
        }
    }

    private function cacheCheck(): void
    {
        try {
            $probe = 'health_check_probe_'.uniqid();
            Cache::put($probe, '1', 5);
            $ok = Cache::get($probe) === '1';
            Cache::forget($probe);
            $this->record($ok ? 'PASS' : 'FAIL', 'cache',
                $ok ? 'التخزين المؤقت يعمل ('.config('cache.default').').' : 'تعذّرت قراءة قيمة الاختبار من التخزين المؤقت.');
        } catch (Throwable $e) {
            $this->record('FAIL', 'cache', 'تعذّر استخدام التخزين المؤقت: '.$e->getMessage());
        }
    }

    private function queueCheck(): void
    {
        $driver = config('queue.default');
        $this->record('PASS', 'queue', 'محرّك قائمة الانتظار: '.$driver);
    }

    private function storageCheck(): void
    {
        $writable = is_writable(storage_path());
        $this->record($writable ? 'PASS' : 'FAIL', 'storage',
            $writable ? 'مجلّد التخزين قابل للكتابة.' : 'مجلّد التخزين غير قابل للكتابة: '.storage_path());
    }

    private function systemAccountsCheck(): void
    {
        $missing = [];
        foreach (SystemAccountKey::cases() as $key) {
            $map = SystemAccount::where('key', $key->value)->first();
            if ($map === null || $map->account === null) {
                $missing[] = $key->value;
            }
        }

        if ($missing === []) {
            $this->record('PASS', 'system_accounts', 'جميع الحسابات النظامية مُعرّفة ('.count(SystemAccountKey::cases()).').');
        } else {
            $this->record('FAIL', 'system_accounts', 'حسابات نظامية مفقودة: '.implode(', ', $missing));
        }
    }

    private function permissionCatalogCheck(): void
    {
        // Every catalog permission must exist as a row, or role editing silently
        // drops modules (Spatie throws PermissionDoesNotExist on sync).
        try {
            $missing = Permissions::missing();
        } catch (Throwable $e) {
            $this->record('WARN', 'permissions.catalog', 'تعذّر فحص كتالوج الصلاحيات: '.$e->getMessage());

            return;
        }

        if ($missing === []) {
            $this->record('PASS', 'permissions.catalog', 'جميع صلاحيات الكتالوج موجودة في قاعدة البيانات.');
        } else {
            $this->record('FAIL', 'permissions.catalog',
                'صلاحيات ناقصة ('.count($missing).'): '.implode(', ', array_slice($missing, 0, 8)).
                ' — شغّل php artisan migrate أو db:seed لإصلاحها.');
        }
    }

    private function settingsChecks(): void
    {
        $settings = app(Settings::class);
        $required = [
            ['finance', 'default_invoice_due_days'],
            ['whatsapp', 'provider'],
            ['whatsapp', 'default_country_code'],
        ];

        foreach ($required as [$group, $key]) {
            $value = $settings->get($group, $key);
            $this->record($value === null ? 'WARN' : 'PASS', "settings.{$group}.{$key}",
                $value === null ? "الإعداد {$group}.{$key} غير موجود." : "الإعداد {$group}.{$key} موجود.");
        }
    }

    private function schedulerCheck(): void
    {
        $registered = array_keys(Artisan::all());
        foreach (['payments:send-reminders'] as $command) {
            in_array($command, $registered, true)
                ? $this->record('PASS', "scheduler.{$command}", "الأمر المجدول {$command} مُسجّل.")
                : $this->record('FAIL', "scheduler.{$command}", "الأمر المجدول {$command} غير مُسجّل.");
        }
    }

    private function whatsappCheck(): void
    {
        $settings = app(Settings::class);
        $enabled = $settings->get('whatsapp', 'enabled', false);
        $provider = $settings->get('whatsapp', 'provider', 'null');

        // Report state only — the provider token (if any) is never read or printed.
        $this->record('PASS', 'whatsapp', 'واتساب: '.($enabled ? 'مفعّل' : 'معطّل').'، المزوّد: '.$provider);
    }

    private function migrationsCheck(): void
    {
        try {
            $migrator = app('migrator');
            $paths = array_merge([database_path('migrations')], $migrator->paths());
            $files = array_keys($migrator->getMigrationFiles($paths));

            if (! $migrator->repositoryExists()) {
                $this->record('WARN', 'migrations', 'جدول الترحيلات غير موجود — لم تُشغّل الترحيلات بعد.');

                return;
            }

            $ran = $migrator->getRepository()->getRan();
            $pending = array_diff($files, $ran);

            count($pending) === 0
                ? $this->record('PASS', 'migrations', 'لا توجد ترحيلات معلّقة.')
                : $this->record('WARN', 'migrations', 'توجد '.count($pending).' ترحيلات معلّقة.');
        } catch (Throwable $e) {
            $this->record('WARN', 'migrations', 'تعذّر فحص الترحيلات: '.$e->getMessage());
        }
    }

    private function accountingIntegrityChecks(): void
    {
        try {
            $tb = app(AccountingReportService::class)->trialBalance();
            $balanced = Money::equals($tb['totals']['ending_debit'], $tb['totals']['ending_credit']);
            $this->record($balanced ? 'PASS' : 'FAIL', 'accounting.trial_balance',
                $balanced ? 'ميزان المراجعة متوازن.' : 'ميزان المراجعة غير متوازن.');
        } catch (Throwable $e) {
            $this->record('FAIL', 'accounting.trial_balance', 'تعذّر احتساب ميزان المراجعة: '.$e->getMessage());
        }

        try {
            $bs = app(AccountingReportService::class)->balanceSheet();
            $this->record($bs['balanced'] ? 'PASS' : 'FAIL', 'accounting.balance_sheet',
                $bs['balanced'] ? 'الميزانية العمومية متوازنة.' : 'الميزانية العمومية غير متوازنة.');
        } catch (Throwable $e) {
            $this->record('FAIL', 'accounting.balance_sheet', 'تعذّر احتساب الميزانية العمومية: '.$e->getMessage());
        }

        try {
            foreach (app(ReconciliationService::class)->all() as $rec) {
                $this->record($rec['balanced'] ? 'PASS' : 'FAIL', 'accounting.reconciliation',
                    $rec['label'].': '.($rec['balanced'] ? 'مطابق' : 'غير مطابق (فرق '.$rec['difference'].')'));
            }
        } catch (Throwable $e) {
            $this->record('FAIL', 'accounting.reconciliation', 'تعذّرت المطابقة المحاسبية: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------- plumbing

    private function record(string $status, string $check, string $message): void
    {
        $this->results[] = ['status' => $status, 'check' => $check, 'message' => $message];

        if ($this->option('json')) {
            return; // defer all output to the single JSON blob
        }

        $line = "[{$status}] {$check} — {$message}";
        match ($status) {
            'PASS' => $this->info($line),
            'WARN' => $this->warn($line),
            default => $this->error($line),
        };
    }
}
