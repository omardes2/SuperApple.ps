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
                'label' => 'الرئيسية',
                'items' => [
                    ['route' => 'admin.dashboard', 'label' => 'لوحة التحكم', 'icon' => 'home', 'permission' => 'dashboard.view'],
                ],
            ],
            [
                'label' => 'العملاء والمبيعات',
                'items' => [
                    ['route' => 'admin.customers', 'label' => 'العملاء', 'icon' => 'users', 'permission' => 'customers.view'],
                    ['route' => 'admin.services', 'label' => 'الخدمات', 'icon' => 'grid', 'permission' => 'services.view'],
                    ['route' => 'admin.invoices', 'label' => 'الفواتير', 'icon' => 'invoice', 'permission' => 'invoices.view'],
                    ['route' => 'admin.payments', 'label' => 'الدفعات والتحصيل', 'icon' => 'cash', 'permission' => 'payments.view'],
                    ['route' => 'admin.subscriptions', 'label' => 'الاشتراكات', 'icon' => 'repeat', 'permission' => 'subscriptions.view'],
                    ['route' => 'admin.whatsapp', 'label' => 'واتساب', 'icon' => 'chat', 'permission' => 'whatsapp.view'],
                    ['route' => 'admin.exchange-rates', 'label' => 'أسعار الصرف', 'icon' => 'repeat', 'permission' => 'exchange_rates.view'],
                ],
            ],
            [
                'label' => 'العمل',
                'items' => [
                    ['route' => 'admin.tasks', 'label' => 'المهام', 'icon' => 'check', 'permission' => 'tasks.view'],
                ],
            ],
            [
                'label' => 'الموظفون',
                'items' => [
                    ['route' => 'admin.employees', 'label' => 'الموظفون', 'icon' => 'badge', 'permission' => 'employees.view'],
                    ['route' => 'admin.departments', 'label' => 'الأقسام', 'icon' => 'grid', 'permission' => 'departments.view'],
                    ['route' => 'admin.attendance', 'label' => 'الدوام', 'icon' => 'clock', 'permission' => 'attendance.view'],
                    ['route' => 'admin.leaves', 'label' => 'الإجازات', 'icon' => 'calendar', 'permission' => 'leaves.view'],
                    ['route' => 'admin.payroll', 'label' => 'الرواتب', 'icon' => 'wallet', 'permission' => 'payroll.view'],
                    ['route' => 'admin.advances', 'label' => 'سلف الموظفين', 'icon' => 'cash', 'permission' => 'advances.view'],
                ],
            ],
            [
                'label' => 'المالية والمحاسبة',
                'items' => [
                    ['route' => 'admin.expenses', 'label' => 'المصاريف', 'icon' => 'minus', 'permission' => 'expenses.view'],
                    ['route' => 'admin.suppliers', 'label' => 'الموردون', 'icon' => 'truck', 'permission' => 'suppliers.view'],
                    ['route' => 'admin.cash-banks', 'label' => 'الصندوق والبنوك', 'icon' => 'bank', 'permission' => 'financial_accounts.view'],
                    ['route' => 'admin.accounting.chart', 'label' => 'دليل الحسابات', 'icon' => 'book', 'permission' => 'chart_accounts.view'],
                    ['route' => 'admin.journals', 'label' => 'القيود المحاسبية', 'icon' => 'doc', 'permission' => 'journals.view'],
                ],
            ],
            [
                'label' => 'التقارير',
                'items' => [
                    ['route' => 'admin.reports', 'label' => 'مركز التقارير', 'icon' => 'chart', 'permission' => 'reports.operational'],
                    ['route' => 'admin.reports.gl', 'label' => 'دفتر الأستاذ', 'icon' => 'book', 'permission' => 'reports.gl'],
                    ['route' => 'admin.reports.trial-balance', 'label' => 'ميزان المراجعة', 'icon' => 'grid', 'permission' => 'reports.trial_balance'],
                    ['route' => 'admin.reports.profit-loss', 'label' => 'قائمة الدخل', 'icon' => 'chart', 'permission' => 'reports.profit_loss'],
                    ['route' => 'admin.reports.balance-sheet', 'label' => 'الميزانية العمومية', 'icon' => 'grid', 'permission' => 'reports.balance_sheet'],
                    ['route' => 'admin.reports.ar-aging', 'label' => 'أعمار الذمم', 'icon' => 'clock', 'permission' => 'reports.ar_aging'],
                    ['route' => 'admin.reports.reconciliation', 'label' => 'المطابقات', 'icon' => 'shield', 'permission' => 'reports.reconciliation'],
                ],
            ],
            [
                'label' => 'النظام',
                'items' => [
                    ['route' => 'admin.notifications', 'label' => 'الإشعارات', 'icon' => 'bell', 'permission' => 'notifications.view'],
                    ['route' => 'admin.activity', 'label' => 'سجل النشاط', 'icon' => 'chart', 'permission' => 'reports.operational'],
                    ['route' => 'admin.users', 'label' => 'المستخدمون', 'icon' => 'users', 'permission' => 'users.view'],
                    ['route' => 'admin.roles', 'label' => 'الأدوار والصلاحيات', 'icon' => 'shield', 'permission' => 'roles.manage'],
                    ['route' => 'admin.audit', 'label' => 'سجل العمليات', 'icon' => 'shield', 'permission' => 'audit.view'],
                    ['route' => 'admin.settings', 'label' => 'الإعدادات', 'icon' => 'cog', 'permission' => 'settings.view'],
                ],
            ],
        ];
    }
}
