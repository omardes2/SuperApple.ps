<?php

namespace App\Livewire\Admin;

use App\Enums\RoleName;
use App\Services\AccountingReportService;
use App\Services\ReconciliationService;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A Super-Admin production-readiness overview. Mirrors the app:health-check
 * command in the UI. Never displays secret values — only whether things are
 * configured.
 */
#[Layout('layouts.app')]
#[Title('جاهزية الإنتاج')]
class ProductionReadiness extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole(RoleName::SuperAdmin->value), 403);
    }

    /** @return list<array{group:string,label:string,status:string,detail:string}> */
    private function checks(): array
    {
        $out = [];
        $settings = app(Settings::class);

        // Application
        $out[] = $this->row('التطبيق', 'إصدار النظام', 'pass', (string) $settings->get('system', 'version', '1.0.0'));
        $out[] = $this->row('التطبيق', 'البيئة (APP_ENV)', app()->environment() === 'production' ? 'pass' : 'warn', app()->environment());
        $out[] = $this->row('التطبيق', 'وضع التصحيح (APP_DEBUG)', config('app.debug') ? 'warn' : 'pass', config('app.debug') ? 'مفعّل' : 'معطّل');
        $out[] = $this->row('التطبيق', 'مفتاح التطبيق (APP_KEY)', config('app.key') ? 'pass' : 'fail', config('app.key') ? 'موجود' : 'مفقود');

        // Database
        try {
            DB::connection()->getPdo();
            $out[] = $this->row('قاعدة البيانات', 'الاتصال', 'pass', config('database.default'));
        } catch (\Throwable $e) {
            $out[] = $this->row('قاعدة البيانات', 'الاتصال', 'fail', 'تعذّر الاتصال');
        }

        // Cache
        try {
            Cache::put('__readiness_probe', '1', 5);
            $ok = Cache::get('__readiness_probe') === '1';
            $out[] = $this->row('التخزين المؤقت', 'Cache', $ok ? 'pass' : 'warn', config('cache.default'));
        } catch (\Throwable $e) {
            $out[] = $this->row('التخزين المؤقت', 'Cache', 'warn', 'تعذّر');
        }

        // Queue + Storage
        $out[] = $this->row('الطابور', 'اتصال الطابور', 'pass', config('queue.default'));
        $out[] = $this->row('التخزين', 'قابلية الكتابة', is_writable(storage_path()) ? 'pass' : 'fail', is_writable(storage_path()) ? 'قابل للكتابة' : 'غير قابل');

        // Scheduler
        $cmds = array_keys(Artisan::all());
        $sched = in_array('subscriptions:bill', $cmds, true) && in_array('payments:send-reminders', $cmds, true);
        $out[] = $this->row('المجدول', 'الأوامر المجدولة', $sched ? 'pass' : 'fail', $sched ? 'مسجّلة' : 'ناقصة');

        // WhatsApp
        $out[] = $this->row('واتساب', 'حالة القناة', 'pass', ($settings->get('whatsapp', 'enabled') ? 'مفعّلة' : 'معطّلة').' — مزوّد: '.$settings->get('whatsapp', 'provider', 'null'));

        // Accounting integrity
        $acc = app(AccountingReportService::class);
        $tb = $acc->trialBalance();
        $out[] = $this->row('المحاسبة', 'ميزان المراجعة', $tb['totals']['ending_debit'] === $tb['totals']['ending_credit'] ? 'pass' : 'fail', 'متوازن؟');
        $out[] = $this->row('المحاسبة', 'الميزانية العمومية', $acc->balanceSheet()['balanced'] ? 'pass' : 'fail', 'متوازنة؟');
        foreach (app(ReconciliationService::class)->all() as $rec) {
            $out[] = $this->row('المطابقات', $rec['label'] ?? 'مطابقة', ($rec['balanced'] ?? false) ? 'pass' : 'fail', ($rec['balanced'] ?? false) ? 'مطابق' : 'غير مطابق');
        }

        return $out;
    }

    private function row(string $group, string $label, string $status, string $detail): array
    {
        return compact('group', 'label', 'status', 'detail');
    }

    public function render()
    {
        $checks = $this->checks();

        return view('livewire.admin.production-readiness', [
            'checks' => $checks,
            'hasFail' => collect($checks)->contains(fn ($c) => $c['status'] === 'fail'),
        ]);
    }
}
