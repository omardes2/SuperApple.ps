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
                'customers.manage' => 'إدارة العملاء والتصنيفات',
                'customers.archive' => 'أرشفة العملاء',
                'customers.attachments' => 'إدارة مرفقات العملاء',
            ]],
            'projects' => ['financial' => false, 'permissions' => [
                'projects.view' => 'عرض جميع المشاريع',
                'projects.view_assigned' => 'عرض المشاريع المسندة',
                'projects.create' => 'إنشاء مشروع',
                'projects.edit' => 'تعديل مشروع',
                'projects.manage' => 'إدارة المشاريع',
                'projects.members' => 'إدارة أعضاء المشروع',
                'projects.attachments' => 'إدارة مرفقات المشاريع',
            ]],
            'tasks' => ['financial' => false, 'permissions' => [
                'tasks.view' => 'عرض جميع المهام',
                'tasks.view_own' => 'عرض المهام الخاصة',
                'tasks.create' => 'إنشاء مهمة',
                'tasks.edit' => 'تعديل مهمة',
                'tasks.assign' => 'إسناد المهام',
                'tasks.manage' => 'إدارة المهام',
                'tasks.review' => 'مراجعة واعتماد المهام',
                'tasks.comment' => 'التعليق على المهام',
                'tasks.attachments' => 'إدارة مرفقات المهام',
                'tasks.checklist' => 'إدارة قائمة التحقق',
            ]],
            'services' => ['financial' => false, 'permissions' => [
                'services.view' => 'عرض الخدمات',
                'services.create' => 'إضافة خدمة',
                'services.edit' => 'تعديل خدمة',
                'services.manage' => 'إدارة الخدمات',
                'services.view_financial' => 'عرض أسعار وتكاليف الخدمات',
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
            'suppliers' => ['financial' => true, 'permissions' => [
                'suppliers.view' => 'عرض الموردين',
                'suppliers.create' => 'إضافة مورد',
                'suppliers.edit' => 'تعديل مورد',
                'suppliers.manage' => 'إدارة الموردين',
                'supplier_bills.view' => 'عرض فواتير الموردين',
                'supplier_bills.create' => 'إنشاء فاتورة مورد',
                'supplier_bills.edit' => 'تعديل فاتورة مورد',
                'supplier_bills.post' => 'ترحيل فاتورة مورد',
                'supplier_bills.cancel' => 'إلغاء فاتورة مورد',
                'supplier_payments.view' => 'عرض دفعات الموردين',
                'supplier_payments.create' => 'إنشاء دفعة مورد',
                'supplier_payments.post' => 'ترحيل دفعة مورد',
                'supplier_payments.cancel' => 'إلغاء دفعة مورد',
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
            'exchange_rates' => ['financial' => true, 'permissions' => [
                'exchange_rates.view' => 'عرض أسعار الصرف',
                'exchange_rates.manage' => 'إدارة أسعار الصرف',
            ]],
            'quotations' => ['financial' => true, 'permissions' => [
                'quotations.view' => 'عرض عروض الأسعار',
                'quotations.manage' => 'إدارة عروض الأسعار',
                'quotations.create' => 'إنشاء عرض سعر',
                'quotations.edit' => 'تعديل عرض سعر',
                'quotations.send' => 'إرسال عرض سعر',
                'quotations.accept' => 'قبول عرض سعر',
                'quotations.reject' => 'رفض عرض سعر',
                'quotations.cancel' => 'إلغاء عرض سعر',
                'quotations.convert' => 'تحويل عرض سعر إلى فاتورة',
                'quotations.print' => 'طباعة عرض السعر',
            ]],
            'invoices' => ['financial' => true, 'permissions' => [
                'invoices.view' => 'عرض الفواتير',
                'invoices.manage' => 'إدارة الفواتير',
                'invoices.create' => 'إنشاء فاتورة',
                'invoices.edit' => 'تعديل فاتورة',
                'invoices.issue' => 'إصدار فاتورة',
                'invoices.send' => 'إرسال فاتورة',
                'invoices.cancel' => 'إلغاء فاتورة',
                'invoices.print' => 'طباعة الفاتورة',
            ]],
            'payments' => ['financial' => true, 'permissions' => [
                'payments.view' => 'عرض الدفعات',
                'payments.manage' => 'إدارة الدفعات',
                'payments.create' => 'إنشاء دفعة',
                'payments.edit' => 'تعديل دفعة',
                'payments.post' => 'ترحيل دفعة',
                'payments.cancel' => 'إلغاء دفعة',
                'payments.allocate' => 'تخصيص الدفعات',
                'payments.print' => 'طباعة إيصال الدفع',
                'customer_statements.view' => 'عرض كشوف حساب العملاء',
                'exchange_gain_loss.view' => 'عرض فروقات سعر الصرف',
            ]],
            'expenses' => ['financial' => true, 'permissions' => [
                'expenses.view' => 'عرض المصاريف',
                'expenses.create' => 'إنشاء مصروف',
                'expenses.edit' => 'تعديل مصروف',
                'expenses.approve' => 'اعتماد مصروف',
                'expenses.post' => 'ترحيل مصروف',
                'expenses.cancel' => 'إلغاء مصروف',
                'expenses.manage' => 'إدارة المصاريف',
                'expense_categories.manage' => 'إدارة فئات المصاريف',
            ]],
            'accounts' => ['financial' => true, 'permissions' => [
                'accounts.view' => 'عرض الصندوق والبنوك',
                'accounts.manage' => 'إدارة الصندوق والبنوك',
                'financial_accounts.view' => 'عرض الحسابات النقدية والبنكية',
                'financial_accounts.manage' => 'إدارة الحسابات النقدية والبنكية',
            ]],
            'accounting' => ['financial' => true, 'permissions' => [
                'accounting.view' => 'عرض المحاسبة',
                'accounting.manage' => 'إدارة المحاسبة والقيود',
                'chart_accounts.view' => 'عرض دليل الحسابات',
                'chart_accounts.manage' => 'إدارة دليل الحسابات',
                'journals.view' => 'عرض القيود المحاسبية',
                'journals.create' => 'إنشاء قيد يدوي',
                'journals.post' => 'ترحيل قيد',
                'journals.reverse' => 'عكس قيد',
                'journals.manual' => 'إنشاء قيود يدوية',
            ]],
            'payroll' => ['financial' => true, 'permissions' => [
                'payroll.view' => 'عرض الرواتب',
                'payroll.manage' => 'إدارة الرواتب',
            ]],
            'reports_financial' => ['financial' => true, 'permissions' => [
                'reports.financial' => 'التقارير المالية',
                'reports.gl' => 'دفتر الأستاذ العام',
                'reports.trial_balance' => 'ميزان المراجعة',
                'reports.profit_loss' => 'قائمة الدخل',
                'reports.balance_sheet' => 'الميزانية العمومية',
                'reports.reconciliation' => 'تقارير المطابقة',
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
                'finance.view', 'payments.view', 'payments.manage',
                'payments.create', 'payments.edit', 'payments.post', 'payments.cancel',
                'payments.allocate', 'payments.print', 'customer_statements.view', 'exchange_gain_loss.view',
                'expenses.view', 'expenses.manage', 'accounts.view', 'accounts.manage',
                'accounting.view', 'accounting.manage', 'payroll.view',
                'reports.financial', 'reports.operational', 'audit.view',
                // Sprint 5 — accounting, expenses, suppliers, cash/banks.
                'suppliers.create', 'suppliers.edit',
                'supplier_bills.view', 'supplier_bills.create', 'supplier_bills.edit',
                'supplier_bills.post', 'supplier_bills.cancel',
                'supplier_payments.view', 'supplier_payments.create',
                'supplier_payments.post', 'supplier_payments.cancel',
                'expenses.create', 'expenses.edit', 'expenses.approve', 'expenses.post',
                'expenses.cancel', 'expense_categories.manage',
                'financial_accounts.view', 'financial_accounts.manage',
                'chart_accounts.view', 'chart_accounts.manage',
                'journals.view', 'journals.create', 'journals.post', 'journals.reverse', 'journals.manual',
                'reports.gl', 'reports.trial_balance', 'reports.profit_loss',
                'reports.balance_sheet', 'reports.reconciliation',
                // Exchange rates + full quotation & invoice lifecycle (Sprint 3).
                'exchange_rates.view', 'exchange_rates.manage',
                'quotations.view', 'quotations.manage', 'quotations.create', 'quotations.edit',
                'quotations.send', 'quotations.accept', 'quotations.reject', 'quotations.cancel',
                'quotations.convert', 'quotations.print',
                'invoices.view', 'invoices.manage', 'invoices.create', 'invoices.edit',
                'invoices.issue', 'invoices.send', 'invoices.cancel', 'invoices.print',
                // Service catalog incl. pricing (needed for quotations/invoices).
                'services.view', 'services.view_financial', 'services.create', 'services.edit', 'services.manage',
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
                'dashboard.view', 'customers.view', 'reports.operational', 'notifications.view',
                'departments.view', 'employees.view', 'attendance.view',
                // Full project & task management (no finance; service prices hidden).
                'projects.view', 'projects.view_assigned', 'projects.create', 'projects.edit',
                'projects.manage', 'projects.members', 'projects.attachments',
                'tasks.view', 'tasks.view_own', 'tasks.create', 'tasks.edit', 'tasks.assign',
                'tasks.manage', 'tasks.review', 'tasks.comment', 'tasks.attachments', 'tasks.checklist',
                'services.view', // NOTE: no services.view_financial — PM cannot see prices/costs.
            ], self::selfService()),

            // Team Leader stays in the operational experience (per Sprint 0/1
            // architecture): an enhanced employee who can create tasks and
            // collaborate, but team-wide assignment/review belongs to PM/GM.
            RoleName::TeamLeader->value => array_merge([
                'dashboard.view', 'notifications.view',
                'projects.view_assigned', 'tasks.view_own', 'tasks.create',
                'tasks.comment', 'tasks.attachments', 'tasks.checklist',
            ], self::selfService()),

            RoleName::Employee->value => array_merge([
                'dashboard.view', 'notifications.view',
                'projects.view_assigned', 'tasks.view_own',
                'tasks.comment', 'tasks.attachments', 'tasks.checklist',
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
