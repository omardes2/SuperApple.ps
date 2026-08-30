<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use App\Livewire\Admin\ArAgingReport;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\ReportsCenter;
use App\Livewire\Admin\UsersIndex;
use App\Services\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DashboardAndReportsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_ar_aging_buckets_by_days_overdue(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->makeCustomer();
        // Issue an invoice, then force its due date far in the past to be overdue.
        $invoice = $this->makeIssuedInvoice($customer, '500', '3.30');
        $invoice->forceFill(['due_date' => now()->subDays(100)->toDateString()])->saveQuietly();

        $aging = app(ReportsService::class)->arAging();
        $this->assertSame('500.00', $aging['buckets']['90_plus']);
        $this->assertSame('500.00', $aging['total']);
    }

    public function test_revenue_chart_uses_gl_and_returns_requested_months(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $rows = app(ReportsService::class)->revenueVsExpenses(12);
        $this->assertCount(12, $rows);
        $this->assertArrayHasKey('revenue', $rows[0]);
        $this->assertArrayHasKey('expense', $rows[0]);
    }

    public function test_dashboard_renders_for_gm_with_executive_widgets(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->makeActiveSubscription();
        Livewire::actingAs($gm)->test(Dashboard::class)
            ->assertOk()
            ->assertViewHas('aging')
            ->assertViewHas('charts');
    }

    public function test_reports_center_hides_finance_links_from_pm(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        Livewire::actingAs($pm)->test(ReportsCenter::class)
            ->assertOk()
            ->assertDontSee('دفتر الأستاذ')
            ->assertSee('تقارير العملاء');
    }

    public function test_employee_cannot_open_reports_or_users(): void
    {
        [$user] = $this->makeStaff();
        Livewire::actingAs($user)->test(ArAgingReport::class)->assertForbidden();
        Livewire::actingAs($user)->test(UsersIndex::class)->assertForbidden();
    }

    public function test_ar_aging_export_requires_export_permission(): void
    {
        // PM has no reports.export nor reports.ar_aging → forbidden on mount.
        $pm = $this->makeUser(RoleName::ProjectManager);
        Livewire::actingAs($pm)->test(ArAgingReport::class)->assertForbidden();
    }
}
