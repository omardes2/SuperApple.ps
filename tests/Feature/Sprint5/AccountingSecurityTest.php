<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Livewire\Admin\ChartOfAccounts;
use App\Livewire\Admin\ExpensesIndex;
use App\Livewire\Admin\JournalsIndex;
use App\Livewire\Admin\SuppliersIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AccountingSecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_employee_cannot_access_accounting_routes(): void
    {
        [$user] = $this->makeStaff();
        foreach (['/admin/accounting/chart', '/admin/accounting/journals', '/admin/accounting/trial-balance',
            '/admin/expenses', '/admin/suppliers', '/admin/cash-banks'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('employee.dashboard'));
        }
    }

    public function test_hr_cannot_access_accounting(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);
        foreach (['accounting.view', 'journals.view', 'expenses.view', 'suppliers.view',
            'reports.gl', 'reports.balance_sheet', 'financial_accounts.view'] as $perm) {
            $this->assertFalse($hr->can($perm), "HR must not have [{$perm}]");
        }
    }

    public function test_pm_cannot_access_gl_or_supplier_balances(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        foreach (['reports.gl', 'accounting.view', 'suppliers.view', 'supplier_bills.view',
            'expenses.view', 'journals.view'] as $perm) {
            $this->assertFalse($pm->can($perm), "PM must not have [{$perm}]");
        }
        $this->actingAs($pm)->get('/admin/accounting/general-ledger')->assertForbidden();
    }

    public function test_accountant_can_access_accounting(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        foreach (['accounting.view', 'journals.view', 'expenses.view', 'suppliers.view',
            'reports.gl', 'reports.trial_balance', 'reports.balance_sheet',
            'reports.reconciliation', 'financial_accounts.view', 'chart_accounts.view'] as $perm) {
            $this->assertTrue($accountant->can($perm), "Accountant should have [{$perm}]");
        }
        $this->actingAs($accountant)->get('/admin/accounting/chart')->assertOk();
        $this->actingAs($accountant)->get('/admin/accounting/trial-balance')->assertOk();
    }

    public function test_unauthorized_user_cannot_enumerate_via_components(): void
    {
        [$user] = $this->makeStaff();
        Livewire::actingAs($user)->test(ChartOfAccounts::class)->assertForbidden();
        Livewire::actingAs($user)->test(JournalsIndex::class)->assertForbidden();
        Livewire::actingAs($user)->test(ExpensesIndex::class)->assertForbidden();
        Livewire::actingAs($user)->test(SuppliersIndex::class)->assertForbidden();
    }

    public function test_unauthorized_user_cannot_post_a_journal(): void
    {
        // A plain employee has no journals.* permission; the policy blocks create.
        [$user] = $this->makeStaff();
        $this->assertFalse($user->can('journals.post'));
        $this->assertFalse($user->can('journals.manual'));
    }

    public function test_general_manager_gets_accounting(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->assertTrue($gm->can('accounting.view'));
        $this->assertTrue($gm->can('reports.balance_sheet'));
        $this->assertTrue($gm->can('journals.reverse'));
    }
}
