<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Locks down the role → permission boundaries across the whole system. If a
 * role ever silently gains a sensitive permission, one of these fails.
 */
class PermissionMatrixTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_employee_is_blocked_from_all_financial_and_admin_areas(): void
    {
        [$user] = $this->makeStaff();
        foreach ([
            'invoices.view', 'payments.view', 'accounting.view', 'payroll.view',
            'payroll.manage', 'suppliers.view', 'subscriptions.view', 'whatsapp.view',
            'whatsapp.send', 'reports.financial', 'users.view', 'roles.manage',
            'reports.ar_aging', 'reports.executive',
        ] as $perm) {
            $this->assertFalse($user->can($perm), "employee must NOT have [{$perm}]");
        }
    }

    public function test_hr_prepares_payroll_but_has_no_gl_or_customer_finance(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);
        $this->assertTrue($hr->can('payroll.calculate'));
        $this->assertTrue($hr->can('employees.view'));
        foreach (['journals.view', 'accounting.view', 'payroll.post', 'payments.view', 'invoices.view', 'reports.ar_aging'] as $perm) {
            $this->assertFalse($hr->can($perm), "HR must NOT have [{$perm}]");
        }
    }

    public function test_accountant_has_finance_but_not_full_hr_management(): void
    {
        $acc = $this->makeUser(RoleName::Accountant);
        $this->assertTrue($acc->can('payments.post'));
        $this->assertTrue($acc->can('payroll.post'));
        $this->assertTrue($acc->can('reports.ar_aging'));
        foreach (['salary_profiles.manage', 'employees.manage', 'roles.manage', 'whatsapp.settings.manage'] as $perm) {
            $this->assertFalse($acc->can($perm), "Accountant must NOT have [{$perm}]");
        }
    }

    public function test_project_manager_has_tasks_but_no_finance(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        $this->assertTrue($pm->can('tasks.manage'));
        $this->assertTrue($pm->can('reports.tasks'));
        // Projects were retired — the permission no longer exists for anyone.
        $this->assertFalse($pm->can('projects.manage'));
        foreach (['invoices.view', 'payments.view', 'journals.view', 'payroll.view', 'reports.financial', 'services.view_financial'] as $perm) {
            $this->assertFalse($pm->can($perm), "PM must NOT have [{$perm}]");
        }
    }

    public function test_general_manager_has_users_but_not_roles_management(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->assertTrue($gm->can('users.manage'));
        $this->assertTrue($gm->can('reports.executive'));
        // roles.manage is reserved for Super Admin.
        $this->assertFalse($gm->can('roles.manage'));
    }

    public function test_super_admin_can_everything(): void
    {
        $sa = $this->makeUser(RoleName::SuperAdmin);
        foreach (['roles.manage', 'users.manage', 'payments.post', 'accounting.manage', 'reports.executive'] as $perm) {
            $this->assertTrue($sa->can($perm), "Super Admin must have [{$perm}]");
        }
    }
}
