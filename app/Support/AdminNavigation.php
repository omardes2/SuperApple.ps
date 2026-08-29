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
                    ['route' => null, 'label' => 'عروض الأسعار', 'icon' => 'doc', 'permission' => 'quotations.view'],
                    ['route' => null, 'label' => 'الفواتير', 'icon' => 'invoice', 'permission' => 'invoices.view'],
                    ['route' => null, 'label' => 'الدفعات والتحصيل', 'icon' => 'cash', 'permission' => 'payments.view'],
                    ['route' => null, 'label' => 'الاشتراكات', 'icon' => 'repeat', 'permission' => 'subscriptions.view'],
                    ['route' => null, 'label' => 'المصاريف', 'icon' => 'minus', 'permission' => 'expenses.view'],
                    ['route' => null, 'label' => 'الموردون', 'icon' => 'truck', 'permission' => 'suppliers.view'],
                    ['route' => null, 'label' => 'الصندوق والبنوك', 'icon' => 'bank', 'permission' => 'accounts.view'],
                    ['route' => null, 'label' => 'المحاسبة', 'icon' => 'book', 'permission' => 'accounting.view'],
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
