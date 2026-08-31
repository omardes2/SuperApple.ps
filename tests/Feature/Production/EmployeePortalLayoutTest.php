<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The unified employee portal shell: every employee page renders inside the
 * same dark-sidebar layout (the payslips design), the old horizontal nav is
 * gone, the correct sidebar item is active, and no financial section leaks in.
 */
class EmployeePortalLayoutTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        [$this->user, $this->employee] = $this->makeStaff(RoleName::Employee);
        $this->actingAs($this->user);
    }

    /** @return list<string> */
    private function pages(): array
    {
        return [
            'employee.dashboard', 'employee.tasks', 'employee.attendance',
            'employee.leaves', 'employee.payslips', 'employee.notifications',
        ];
    }

    private function assertActive(string $html, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '/bg-brand-600 text-white.*?<span>'.preg_quote($label, '/').'<\/span>/s',
            $html,
            "expected sidebar item [{$label}] to be active"
        );
    }

    // ---- Unified shell on every page ----

    public function test_every_employee_page_uses_the_unified_dark_sidebar_shell(): void
    {
        foreach ($this->pages() as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();
            // Dark sidebar + grouped nav headers that only the unified shell renders.
            $this->assertStringContainsString('bg-slate-900', $html, "sidebar missing on {$route}");
            $this->assertStringContainsString('العمل', $html, "work group missing on {$route}");
            $this->assertStringContainsString('النظام', $html, "system group missing on {$route}");
        }
    }

    public function test_pages_are_rtl(): void
    {
        foreach ($this->pages() as $route) {
            $this->get(route($route))->assertSee('dir="rtl"', false);
        }
    }

    public function test_old_horizontal_employee_nav_is_gone(): void
    {
        // The old top nav rendered items with this exact class combination.
        $html = $this->get(route('employee.dashboard'))->getContent();
        $this->assertStringNotContainsString('border-b-2 px-4 py-3', $html);
    }

    // ---- Active menu per page ----

    public function test_active_menu_dashboard(): void
    {
        $this->assertActive($this->get(route('employee.dashboard'))->getContent(), 'الرئيسية');
    }

    public function test_active_menu_tasks(): void
    {
        $this->assertActive($this->get(route('employee.tasks'))->getContent(), 'مهامي');
    }

    public function test_active_menu_tasks_detail_keeps_tasks_active(): void
    {
        $customer = $this->makeCustomer();
        $service = Service::create([
            'service_code' => 'LAY-1', 'name' => 'خدمة', 'category' => 'تصميم',
            'service_type' => 'custom', 'is_active' => true,
        ]);
        $task = app(TaskService::class)->create([
            'title' => 'مهمة', 'customer_id' => $customer->id, 'service_ids' => [$service->id],
            'primary_assignee_id' => $this->employee->id, 'start_date' => now()->toDateString(),
            'due_date' => now()->toDateString(), 'priority' => 'normal',
        ]);

        $this->assertActive($this->get(route('employee.tasks.show', $task))->getContent(), 'مهامي');
    }

    public function test_active_menu_attendance(): void
    {
        $this->assertActive($this->get(route('employee.attendance'))->getContent(), 'الدوام');
    }

    public function test_active_menu_leaves(): void
    {
        $this->assertActive($this->get(route('employee.leaves'))->getContent(), 'الإجازات');
    }

    public function test_active_menu_payslips(): void
    {
        $this->assertActive($this->get(route('employee.payslips'))->getContent(), 'قسائم راتبي');
    }

    public function test_active_menu_notifications(): void
    {
        $this->assertActive($this->get(route('employee.notifications'))->getContent(), 'الإشعارات');
    }

    // ---- No financial sections in the employee sidebar ----

    public function test_sidebar_has_no_financial_sections(): void
    {
        $html = $this->get(route('employee.dashboard'))->getContent();
        foreach (['الفواتير', 'الدفعات', 'المصاريف', 'دليل الحسابات', 'القيود المحاسبية', 'الصندوق والبنوك', 'الموردون'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "sidebar must not contain [{$forbidden}]");
        }
    }

    public function test_employee_header_has_no_admin_global_search(): void
    {
        $html = $this->get(route('employee.dashboard'))->getContent();
        // The admin global-search Livewire component must never render here.
        $this->assertStringNotContainsString('admin.global-search', $html);
    }

    // ---- Mobile / responsive ----

    public function test_mobile_navigation_renders_without_exception(): void
    {
        // The off-canvas toggle (Alpine) and hamburger are present and render.
        $this->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSee('x-data', false)
            ->assertSee('open = !open', false);
    }

    // ---- Security: financial routes still blocked after the refactor ----

    public function test_employee_cannot_access_financial_routes(): void
    {
        foreach (['/admin/invoices', '/admin/payments', '/admin/accounting/chart', '/admin/expenses'] as $url) {
            $this->get($url)->assertRedirect(route('employee.dashboard'));
        }
    }

    // ---- Functionality preserved ----

    public function test_dashboard_no_longer_shows_financial_placeholder(): void
    {
        $this->get(route('employee.dashboard'))->assertDontSee('لا توجد أي بيانات مالية');
    }

    public function test_notifications_page_shows_only_own_and_hides_finance(): void
    {
        // A finance-category notification must never surface for an employee.
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Generic',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => json_encode(['type' => 'invoice.created', 'message' => 'سر مالي مخفي']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get(route('employee.notifications'))->assertOk()->assertDontSee('سر مالي مخفي');
    }

    // ---- No N+1 from the shell header ----

    public function test_shell_header_does_not_cause_query_explosion(): void
    {
        // Seed several unread notifications; the header badge must not scale per row.
        for ($i = 0; $i < 8; $i++) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\Generic',
                'notifiable_type' => User::class,
                'notifiable_id' => $this->user->id,
                'data' => json_encode(['type' => 'task.assigned', 'message' => 'n'.$i]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::enableQueryLog();
        $this->get(route('employee.dashboard'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(40, $count, "employee dashboard issued {$count} queries — possible N+1");
    }
}
