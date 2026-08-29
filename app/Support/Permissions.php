<?php

namespace App\Support;

use App\Enums\RoleName;

/**
 * Single source of truth for the permission catalog and the default
 * role → permission bundles. Consumed by the seeder and role-management UI.
 */
final class Permissions
{
    /**
     * Permissions grouped by module (group => [permission => arabic label]).
     * `financial` flags the groups that must never reach a plain employee.
     *
     * @return array<string,array{financial:bool,permissions:array<string,string>}>
     */
    public static function catalog(): array
    {
        return [
            'dashboard' => ['financial' => false, 'permissions' => [
                'dashboard.view' => 'عرض لوحة التحكم',
            ]],
            'customers' => ['financial' => false, 'permissions' => [
                'customers.view' => 'عرض العملاء',
                'customers.create' => 'إضافة عميل',
                'customers.edit' => 'تعديل عميل',
                'customers.delete' => 'حذف عميل',
            ]],
            'projects' => ['financial' => false, 'permissions' => [
                'projects.view' => 'عرض المشاريع',
                'projects.manage' => 'إدارة المشاريع',
            ]],
            'tasks' => ['financial' => false, 'permissions' => [
                'tasks.view' => 'عرض المهام',
                'tasks.create' => 'إنشاء مهمة',
                'tasks.assign' => 'إسناد المهام',
                'tasks.review' => 'مراجعة المهام',
            ]],
            'services' => ['financial' => false, 'permissions' => [
                'services.view' => 'عرض الخدمات',
                'services.manage' => 'إدارة الخدمات',
            ]],
            'departments' => ['financial' => false, 'permissions' => [
                'departments.view' => 'عرض الأقسام',
                'departments.create' => 'إضافة قسم',
                'departments.edit' => 'تعديل قسم',
                'departments.manage' => 'إدارة الأقسام',
            ]],
            'employees' => ['financial' => false, 'permissions' => [
                'employees.view' => 'عرض الموظفين',
                'employees.create' => 'إضافة موظف',
                'employees.edit' => 'تعديل موظف',
                'employees.manage' => 'إدارة الموظفين',
                'employees.documents' => 'إدارة مستندات الموظفين',
            ]],
            'attendance' => ['financial' => false, 'permissions' => [
                'attendance.view' => 'عرض دوام جميع الموظفين',
                'attendance.view_own' => 'عرض الدوام الخاص',
                'attendance.check_in' => 'تسجيل الحضور',
                'attendance.check_out' => 'تسجيل الانصراف',
                'attendance.manage' => 'إدارة الدوام',
                'attendance.adjust' => 'تعديل سجلات الدوام',
                'attendance.reports' => 'تقارير الدوام',
            ]],
            'leaves' => ['financial' => false, 'permissions' => [
                'leaves.view' => 'عرض إجازات جميع الموظفين',
                'leaves.view_own' => 'عرض الإجازات الخاصة',
                'leaves.request' => 'تقديم طلب إجازة',
                'leaves.create' => 'إنشاء طلب إجازة',
                'leaves.approve' => 'اعتماد الإجازات',
                'leaves.reject' => 'رفض الإجازات',
                'leaves.manage' => 'إدارة الإجازات',
            ]],
            'suppliers' => ['financial' => false, 'permissions' => [
                'suppliers.view' => 'عرض الموردين',
                'suppliers.manage' => 'إدارة الموردين',
            ]],
            'subscriptions' => ['financial' => false, 'permissions' => [
                'subscriptions.view' => 'عرض الاشتراكات',
                'subscriptions.manage' => 'إدارة الاشتراكات',
            ]],
            'whatsapp' => ['financial' => false, 'permissions' => [
                'whatsapp.view' => 'عرض واتساب',
                'whatsapp.send' => 'إرسال رسائل واتساب',
            ]],
            'notifications' => ['financial' => false, 'permissions' => [
                'notifications.view' => 'عرض الإشعارات',
            ]],
            'settings' => ['financial' => false, 'permissions' => [
                'settings.view' => 'عرض الإعدادات',
                'settings.manage' => 'إدارة الإعدادات',
            ]],
            'audit' => ['financial' => false, 'permissions' => [
                'audit.view' => 'عرض سجل العمليات',
            ]],
            'roles' => ['financial' => false, 'permissions' => [
                'roles.manage' => 'إدارة الصلاحيات والأدوار',
            ]],
            'reports_operational' => ['financial' => false, 'permissions' => [
                'reports.operational' => 'التقارير التشغيلية',
            ]],

            // ---- Financial groups (guarded) ----
            'finance' => ['financial' => true, 'permissions' => [
                'finance.view' => 'الوصول للقسم المالي',
            ]],
            'quotations' => ['financial' => true, 'permissions' => [
                'quotations.view' => 'عرض عروض الأسعار',
                'quotations.manage' => 'إدارة عروض الأسعار',
            ]],
            'invoices' => ['financial' => true, 'permissions' => [
                'invoices.view' => 'عرض الفواتير',
                'invoices.manage' => 'إدارة الفواتير',
            ]],
            'payments' => ['financial' => true, 'permissions' => [
                'payments.view' => 'عرض الدفعات',
                'payments.manage' => 'إدارة الدفعات',
            ]],
            'expenses' => ['financial' => true, 'permissions' => [
                'expenses.view' => 'عرض المصاريف',
                'expenses.manage' => 'إدارة المصاريف',
            ]],
            'accounts' => ['financial' => true, 'permissions' => [
                'accounts.view' => 'عرض الصندوق والبنوك',
                'accounts.manage' => 'إدارة الصندوق والبنوك',
            ]],
            'accounting' => ['financial' => true, 'permissions' => [
                'accounting.view' => 'عرض المحاسبة',
                'accounting.manage' => 'إدارة المحاسبة والقيود',
            ]],
            'payroll' => ['financial' => true, 'permissions' => [
                'payroll.view' => 'عرض الرواتب',
                'payroll.manage' => 'إدارة الرواتب',
            ]],
            'reports_financial' => ['financial' => true, 'permissions' => [
                'reports.financial' => 'التقارير المالية',
            ]],
        ];
    }

    /**
     * Flat list of all permission names.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];
        foreach (self::catalog() as $group) {
            foreach (array_keys($group['permissions']) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Permission names that are financial / must be hidden from employees.
     *
     * @return list<string>
     */
    public static function financial(): array
    {
        $names = [];
        foreach (self::catalog() as $group) {
            if ($group['financial']) {
                foreach (array_keys($group['permissions']) as $name) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Default permission bundle per role. Super Admin is omitted — it bypasses
     * all checks through Gate::before.
     *
     * @return array<string,list<string>>
     */
    public static function roleDefaults(): array
    {
        return [
            RoleName::GeneralManager->value => array_values(array_diff(self::all(), [
                'roles.manage', // reserved for Super Admin by default
            ])),

            RoleName::Accountant->value => array_merge([
                'dashboard.view', 'customers.view', 'suppliers.view', 'suppliers.manage',
                'subscriptions.view', 'subscriptions.manage', 'notifications.view',
                'finance.view', 'quotations.view', 'quotations.manage',
                'invoices.view', 'invoices.manage', 'payments.view', 'payments.manage',
                'expenses.view', 'expenses.manage', 'accounts.view', 'accounts.manage',
                'accounting.view', 'accounting.manage', 'payroll.view',
                'reports.financial', 'reports.operational', 'audit.view',
            ], self::selfService()),

            RoleName::HrManager->value => array_merge([
                'dashboard.view', 'notifications.view', 'reports.operational',
                'departments.view', 'departments.create', 'departments.edit', 'departments.manage',
                'employees.view', 'employees.create', 'employees.edit', 'employees.manage', 'employees.documents',
                'attendance.view', 'attendance.manage', 'attendance.adjust', 'attendance.reports',
                'leaves.view', 'leaves.approve', 'leaves.reject', 'leaves.manage',
                'payroll.view', 'payroll.manage',
            ], self::selfService()),

            RoleName::ProjectManager->value => array_merge([
                'dashboard.view', 'customers.view', 'projects.view', 'projects.manage',
                'tasks.view', 'tasks.create', 'tasks.assign', 'tasks.review',
                'services.view', 'reports.operational', 'notifications.view',
                'departments.view', 'employees.view', 'attendance.view',
            ], self::selfService()),

            RoleName::TeamLeader->value => array_merge([
                'dashboard.view', 'projects.view', 'tasks.view', 'tasks.create',
                'tasks.assign', 'tasks.review', 'notifications.view',
            ], self::selfService()),

            RoleName::Employee->value => array_merge([
                'dashboard.view', 'projects.view', 'tasks.view', 'notifications.view',
            ], self::selfService()),
        ];
    }

    /**
     * Personal HR self-service every staff member gets: see own attendance,
     * check in/out, and file/see own leave. No visibility into other people's
     * records and nothing financial.
     *
     * @return list<string>
     */
    public static function selfService(): array
    {
        return [
            'attendance.view_own', 'attendance.check_in', 'attendance.check_out',
            'leaves.view_own', 'leaves.create', 'leaves.request',
        ];
    }
}
