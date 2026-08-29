<?php

namespace Tests\Feature\Sprint0;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PageRenderingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_admin_dashboard_renders_for_manager(): void
    {
        $manager = $this->makeUser(RoleName::GeneralManager);

        $this->actingAs($manager)->get('/admin')->assertOk()->assertSee('مرحباً');
    }

    public function test_employee_dashboard_renders_and_hides_finance(): void
    {
        $employee = $this->makeUser(RoleName::Employee);

        $response = $this->actingAs($employee)->get('/employee')->assertOk();
        $response->assertSee('صباح الخير');
        // No financial words leak into the employee dashboard.
        $response->assertDontSee('الفواتير');
        $response->assertDontSee('الرواتب');
    }

    public function test_settings_page_renders_for_manager(): void
    {
        $manager = $this->makeUser(RoleName::GeneralManager);

        $this->actingAs($manager)->get('/admin/settings')->assertOk()->assertSee('بيانات الشركة');
    }

    public function test_audit_page_renders_for_manager(): void
    {
        $manager = $this->makeUser(RoleName::GeneralManager);

        $this->actingAs($manager)->get('/admin/audit-log')->assertOk();
    }

    public function test_admin_sidebar_hides_financial_sections_for_non_financial_manager(): void
    {
        // HR manager: should see HR sidebar entries but no invoices/payments links.
        $hr = $this->makeUser(RoleName::HrManager);

        $response = $this->actingAs($hr)->get('/admin')->assertOk();
        $response->assertSee('الرواتب');       // HR manages payroll
        $response->assertSee('الموظفون');
        $response->assertDontSee('الفواتير');    // not an invoice viewer
        $response->assertDontSee('الدفعات والتحصيل');
    }
}
