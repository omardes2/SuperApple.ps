<?php

namespace App\Support;

/**
 * Central, reasoned permission-dependency map for the ROLE EDITOR.
 *
 * Every operational permission inside an admin module requires that module's
 * "view" permission — you cannot create/edit/assign/post inside a module you
 * are not even allowed to open. When a role is saved, the selected permissions
 * are expanded through this map so an admin can never end up with, say,
 * tasks.create + tasks.assign but no tasks.view (which would hide the module
 * from the sidebar and block /admin/tasks).
 *
 * Deliberately NOT expanded here: the employee-context permissions that live in
 * the same module but belong to the employee portal (tasks.view_own,
 * tasks.comment/attachments/checklist, attendance.check_in/view_own,
 * leaves.request/view_own, …). A view_own permission must never imply the
 * admin `view`, or an employee would gain the back-office listing.
 */
final class PermissionDependencies
{
    /**
     * module view permission => operational permissions that require it.
     *
     * @return array<string,list<string>>
     */
    private static function requiresView(): array
    {
        return [
            'tasks.view' => ['tasks.create', 'tasks.edit', 'tasks.assign', 'tasks.manage', 'tasks.review'],
            'customers.view' => ['customers.create', 'customers.edit', 'customers.delete', 'customers.manage', 'customers.archive', 'customers.attachments', 'customers.opening_balance.manage', 'customers.import'],
            'services.view' => ['services.create', 'services.edit', 'services.manage', 'services.view_financial'],
            'departments.view' => ['departments.create', 'departments.edit', 'departments.manage'],
            'employees.view' => ['employees.create', 'employees.edit', 'employees.manage', 'employees.documents'],
            'attendance.view' => ['attendance.manage', 'attendance.adjust', 'attendance.reports'],
            'leaves.view' => ['leaves.approve', 'leaves.reject', 'leaves.manage'],
            'suppliers.view' => ['suppliers.create', 'suppliers.edit', 'suppliers.manage'],
            'supplier_bills.view' => ['supplier_bills.create', 'supplier_bills.edit', 'supplier_bills.post', 'supplier_bills.cancel'],
            'supplier_payments.view' => ['supplier_payments.create', 'supplier_payments.post', 'supplier_payments.cancel'],
            'whatsapp.view' => ['whatsapp.send', 'whatsapp.retry', 'whatsapp.templates.view', 'whatsapp.templates.manage', 'whatsapp.settings.manage', 'whatsapp.reminders.view', 'whatsapp.reminders.manage', 'whatsapp.history.view'],
            'invoices.view' => ['invoices.manage', 'invoices.create', 'invoices.edit', 'invoices.issue', 'invoices.send', 'invoices.cancel', 'invoices.print'],
            'payments.view' => ['payments.manage', 'payments.create', 'payments.edit', 'payments.post', 'payments.cancel', 'payments.allocate', 'payments.print'],
            'expenses.view' => ['expenses.create', 'expenses.edit', 'expenses.approve', 'expenses.post', 'expenses.cancel', 'expenses.manage', 'expense_categories.manage'],
            'accounts.view' => ['accounts.manage'],
            'financial_accounts.view' => ['financial_accounts.manage'],
            'accounting.view' => ['accounting.manage'],
            'chart_accounts.view' => ['chart_accounts.manage'],
            'journals.view' => ['journals.create', 'journals.post', 'journals.reverse', 'journals.manual'],
            'payroll.view' => ['payroll.manage', 'payroll.create', 'payroll.calculate', 'payroll.approve', 'payroll.post', 'payroll.reverse', 'payroll.pay', 'payroll.reports'],
            'salary_profiles.view' => ['salary_profiles.manage'],
            'salary_adjustments.view' => ['salary_adjustments.manage'],
            'advances.view' => ['advances.create', 'advances.approve', 'advances.pay', 'advances.manage'],
            'payroll_payments.view' => ['payroll_payments.create', 'payroll_payments.reverse'],
            'settings.view' => ['settings.manage'],
            'users.view' => ['users.manage'],
        ];
    }

    /**
     * permission => list of prerequisite permissions it depends on.
     *
     * @return array<string,list<string>>
     */
    public static function map(): array
    {
        $map = [];
        foreach (self::requiresView() as $view => $operations) {
            foreach ($operations as $operation) {
                $map[$operation][] = $view;
            }
        }

        return $map;
    }

    /**
     * Expand a set of permissions to include every prerequisite (transitively),
     * so selecting an operation always carries its module's view permission.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function expand(array $permissions): array
    {
        $map = self::map();
        $set = array_fill_keys($permissions, true);

        do {
            $added = false;
            foreach (array_keys($set) as $permission) {
                foreach ($map[$permission] ?? [] as $prerequisite) {
                    if (! isset($set[$prerequisite])) {
                        $set[$prerequisite] = true;
                        $added = true;
                    }
                }
            }
        } while ($added);

        return array_keys($set);
    }
}
