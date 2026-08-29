<?php

namespace Tests\Feature\Sprint0;

use App\Enums\RoleName;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_employee_has_no_financial_permissions_at_all(): void
    {
        $employee = $this->makeUser(RoleName::Employee);

        foreach (Permissions::financial() as $permission) {
            $this->assertFalse(
                $employee->can($permission),
                "Employee must NOT have financial permission [{$permission}]"
            );
        }
    }

    public function test_team_leader_has_no_financial_permissions(): void
    {
        $lead = $this->makeUser(RoleName::TeamLeader);

        foreach (Permissions::financial() as $permission) {
            $this->assertFalse($lead->can($permission), "Team leader must NOT have [{$permission}]");
        }
    }

    public function test_employee_is_redirected_away_from_admin_area(): void
    {
        $employee = $this->makeUser(RoleName::Employee);

        // dashboard.view is shared, but the admin.area middleware bounces them out.
        $this->actingAs($employee)->get('/admin')->assertRedirect(route('employee.dashboard'));
    }

    public function test_employee_cannot_reach_admin_subpages(): void
    {
        $employee = $this->makeUser(RoleName::Employee);

        // Bounced out of the whole back-office by the admin.area guard.
        $this->actingAs($employee)->get('/admin/audit-log')->assertRedirect(route('employee.dashboard'));
        $this->actingAs($employee)->get('/admin/settings')->assertRedirect(route('employee.dashboard'));
    }

    public function test_admin_user_without_permission_gets_403(): void
    {
        // HR manager has admin experience but is NOT granted settings.view / audit.view.
        $hr = $this->makeUser(RoleName::HrManager);

        $this->actingAs($hr)->get('/admin/settings')->assertForbidden();
        $this->actingAs($hr)->get('/admin/audit-log')->assertForbidden();
    }

    public function test_project_manager_cannot_view_financial_data(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);

        $this->assertFalse($pm->can('invoices.view'));
        $this->assertFalse($pm->can('payments.view'));
        $this->assertFalse($pm->can('payroll.view'));
        $this->assertFalse($pm->can('finance.view'));
        $this->assertFalse($pm->can('reports.financial'));

        // But keeps its operational abilities.
        $this->assertTrue($pm->can('projects.manage'));
        $this->assertTrue($pm->can('tasks.assign'));
    }

    public function test_accountant_can_view_financial_data_but_not_hr_admin(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);

        $this->assertTrue($accountant->can('invoices.view'));
        $this->assertTrue($accountant->can('payments.manage'));
        $this->assertTrue($accountant->can('finance.view'));
        $this->assertTrue($accountant->can('reports.financial'));

        // Accountant only *views* payroll, cannot manage it, and cannot manage employees.
        $this->assertTrue($accountant->can('payroll.view'));
        $this->assertFalse($accountant->can('payroll.manage'));
        $this->assertFalse($accountant->can('employees.manage'));
    }

    public function test_hr_manager_manages_payroll_but_not_invoices(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);

        $this->assertTrue($hr->can('payroll.manage'));
        $this->assertTrue($hr->can('employees.manage'));
        $this->assertTrue($hr->can('leaves.approve'));
        $this->assertFalse($hr->can('invoices.view'));
        $this->assertFalse($hr->can('finance.view'));
    }

    public function test_super_admin_passes_every_ability_via_gate_before(): void
    {
        $admin = $this->makeUser(RoleName::SuperAdmin);

        $this->assertTrue($admin->can('invoices.manage'));
        $this->assertTrue($admin->can('payroll.manage'));
        // Even an ability that does not exist as a permission.
        $this->assertTrue($admin->can('some.future.ability'));
    }

    public function test_accountant_can_reach_settings_only_if_permitted(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        // Accountant is not granted settings.view by default.
        $this->actingAs($accountant)->get('/admin/settings')->assertForbidden();
    }
}
