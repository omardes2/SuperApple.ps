<?php

namespace App\Livewire\Admin;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('مركز التقارير')]
class ReportsCenter extends Component
{
    public function mount(): void
    {
        // Any user with at least one report permission may open the hub; each
        // link is still gated individually below and on its own route.
        abort_unless($this->hasAnyReport(), 403);
    }

    private function hasAnyReport(): bool
    {
        $user = auth()->user();
        foreach ($this->allPermissions() as $perm) {
            if ($user->can($perm)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function allPermissions(): array
    {
        $perms = [];
        foreach ($this->groups() as $group) {
            foreach ($group['items'] as $item) {
                $perms[] = $item['permission'];
            }
        }

        return array_unique($perms);
    }

    /** @return list<array{label:string,items:list<array{label:string,route:string,permission:string,financial?:bool}>}> */
    public function groups(): array
    {
        return [
            ['label' => 'التقارير المالية', 'items' => [
                ['label' => 'دفتر الأستاذ العام', 'route' => 'admin.reports.gl', 'permission' => 'reports.gl'],
                ['label' => 'ميزان المراجعة', 'route' => 'admin.reports.trial-balance', 'permission' => 'reports.trial_balance'],
                ['label' => 'قائمة الدخل', 'route' => 'admin.reports.profit-loss', 'permission' => 'reports.profit_loss'],
                ['label' => 'الميزانية العمومية', 'route' => 'admin.reports.balance-sheet', 'permission' => 'reports.balance_sheet'],
                ['label' => 'أعمار الذمم المدينة (AR Aging)', 'route' => 'admin.reports.ar-aging', 'permission' => 'reports.ar_aging'],
                ['label' => 'المطابقات المحاسبية', 'route' => 'admin.reports.reconciliation', 'permission' => 'reports.reconciliation'],
                ['label' => 'فروقات سعر الصرف', 'route' => 'admin.reports.exchange-gain-loss', 'permission' => 'exchange_gain_loss.view'],
                ['label' => 'ملخص الرواتب', 'route' => 'admin.payroll.reports', 'permission' => 'payroll.reports'],
            ]],
            ['label' => 'تقارير العملاء', 'items' => [
                ['label' => 'العملاء والأرصدة', 'route' => 'admin.reports.customers', 'permission' => 'reports.customers'],
            ]],
            ['label' => 'تقارير المشاريع والمهام', 'items' => [
                ['label' => 'المشاريع والمهام', 'route' => 'admin.reports.projects', 'permission' => 'reports.projects'],
            ]],
            ['label' => 'تقارير الموظفين', 'items' => [
                ['label' => 'الدوام والحضور', 'route' => 'admin.attendance', 'permission' => 'reports.attendance_report'],
            ]],
            ['label' => 'تقارير الاشتراكات', 'items' => [
                ['label' => 'الاشتراكات وMRR/ARR', 'route' => 'admin.reports.subscriptions', 'permission' => 'reports.subscriptions'],
            ]],
            ['label' => 'تقارير المراسلات', 'items' => [
                ['label' => 'واتساب والتذكيرات', 'route' => 'admin.reports.whatsapp', 'permission' => 'reports.whatsapp'],
            ]],
        ];
    }

    public function render()
    {
        return view('livewire.admin.reports-center', ['groups' => $this->groups()]);
    }
}
