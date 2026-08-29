<?php

namespace App\Support;

/**
 * Back-office sidebar definition. Each item declares the permission needed to
 * see it; the layout renders only permitted items so financial sections never
 * appear for unauthorised users. Real enforcement lives on routes + policies.
 */
final class AdminNavigation
{
    /**
     * @return list<array{label:string,items:list<array{route:?string,label:string,icon:string,permission:string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'label' => 'عام',
                'items' => [
                    ['route' => 'admin.dashboard', 'label' => 'الرئيسية', 'icon' => 'home', 'permission' => 'dashboard.view'],
                ],
            ],
            [
                'label' => 'العلاقات والمشاريع',
                'items' => [
                    ['route' => 'admin.customers', 'label' => 'العملاء', 'icon' => 'users', 'permission' => 'customers.view'],
                    ['route' => 'admin.projects', 'label' => 'المشاريع', 'icon' => 'folder', 'permission' => 'projects.view'],
                    ['route' => 'admin.tasks', 'label' => 'المهام', 'icon' => 'check', 'permission' => 'tasks.view'],
                    ['route' => 'admin.services', 'label' => 'الخدمات', 'icon' => 'grid', 'permission' => 'services.view'],
                ],
            ],
            [
                'label' => 'المالية',
                'items' => [
                    ['route' => 'admin.quotations', 'label' => 'عروض الأسعار', 'icon' => 'doc', 'permission' => 'quotations.view'],
                    ['route' => 'admin.invoices', 'label' => 'الفواتير', 'icon' => 'invoice', 'permission' => 'invoices.view'],
                    ['route' => 'admin.exchange-rates', 'label' => 'أسعار الصرف', 'icon' => 'repeat', 'permission' => 'exchange_rates.view'],
                    ['route' => 'admin.payments', 'label' => 'الدفعات والتحصيل', 'icon' => 'cash', 'permission' => 'payments.view'],
                    ['route' => 'admin.reports.exchange-gain-loss', 'label' => 'فروقات الصرف', 'icon' => 'chart', 'permission' => 'exchange_gain_loss.view'],
                    ['route' => 'admin.expenses', 'label' => 'المصاريف', 'icon' => 'minus', 'permission' => 'expenses.view'],
                    ['route' => 'admin.suppliers', 'label' => 'الموردون', 'icon' => 'truck', 'permission' => 'suppliers.view'],
                    ['route' => 'admin.cash-banks', 'label' => 'الصندوق والبنوك', 'icon' => 'bank', 'permission' => 'financial_accounts.view'],
                    ['route' => null, 'label' => 'الاشتراكات', 'icon' => 'repeat', 'permission' => 'subscriptions.view'],
                ],
            ],
            [
                'label' => 'المحاسبة',
                'items' => [
                    ['route' => 'admin.accounting.chart', 'label' => 'دليل الحسابات', 'icon' => 'book', 'permission' => 'chart_accounts.view'],
                    ['route' => 'admin.journals', 'label' => 'القيود المحاسبية', 'icon' => 'doc', 'permission' => 'journals.view'],
                    ['route' => 'admin.reports.gl', 'label' => 'دفتر الأستاذ', 'icon' => 'book', 'permission' => 'reports.gl'],
                    ['route' => 'admin.reports.trial-balance', 'label' => 'ميزان المراجعة', 'icon' => 'grid', 'permission' => 'reports.trial_balance'],
                    ['route' => 'admin.reports.profit-loss', 'label' => 'قائمة الدخل', 'icon' => 'chart', 'permission' => 'reports.profit_loss'],
                    ['route' => 'admin.reports.balance-sheet', 'label' => 'الميزانية العمومية', 'icon' => 'grid', 'permission' => 'reports.balance_sheet'],
                    ['route' => 'admin.reports.reconciliation', 'label' => 'المطابقات', 'icon' => 'shield', 'permission' => 'reports.reconciliation'],
                ],
            ],
            [
                'label' => 'الموارد البشرية',
                'items' => [
                    ['route' => 'admin.employees', 'label' => 'الموظفون', 'icon' => 'badge', 'permission' => 'employees.view'],
                    ['route' => 'admin.departments', 'label' => 'الأقسام', 'icon' => 'grid', 'permission' => 'departments.view'],
                    ['route' => 'admin.attendance', 'label' => 'الدوام', 'icon' => 'clock', 'permission' => 'attendance.view'],
                    ['route' => 'admin.leaves', 'label' => 'الإجازات', 'icon' => 'calendar', 'permission' => 'leaves.view'],
                    ['route' => null, 'label' => 'الرواتب', 'icon' => 'wallet', 'permission' => 'payroll.view'],
                ],
            ],
            [
                'label' => 'أدوات',
                'items' => [
                    ['route' => null, 'label' => 'واتساب', 'icon' => 'chat', 'permission' => 'whatsapp.view'],
                    ['route' => null, 'label' => 'التقارير', 'icon' => 'chart', 'permission' => 'reports.operational'],
                    ['route' => 'admin.audit', 'label' => 'سجل العمليات', 'icon' => 'shield', 'permission' => 'audit.view'],
                    ['route' => 'admin.settings', 'label' => 'الإعدادات', 'icon' => 'cog', 'permission' => 'settings.view'],
                ],
            ],
        ];
    }
}
