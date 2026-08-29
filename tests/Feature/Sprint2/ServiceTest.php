<?php

namespace Tests\Feature\Sprint2;

use App\Enums\RoleName;
use App\Models\Service;
use App\Services\ServiceCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function makeService(array $attrs = []): Service
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));

        return app(ServiceCatalogService::class)->create(array_merge([
            'name' => 'تصميم شعار',
            'service_type' => 'one_time',
            'default_price_usd' => 250,
            'estimated_cost_ils' => 300,
            'tax_rate' => 16,
        ], $attrs));
    }

    public function test_authorized_user_can_create_service_with_code(): void
    {
        $service = $this->makeService();

        $this->assertNotNull($service->service_code);
        $this->assertStringStartsWith('SRV-', $service->service_code);
    }

    public function test_service_code_is_unique(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        app(ServiceCatalogService::class)->create(['name' => 'أ', 'service_type' => 'one_time', 'service_code' => 'SRV-DUP']);

        $this->expectException(QueryException::class);
        app(ServiceCatalogService::class)->create(['name' => 'ب', 'service_type' => 'one_time', 'service_code' => 'SRV-DUP']);
    }

    public function test_employee_cannot_see_service_price_in_serialized_output(): void
    {
        $service = $this->makeService();

        // Serialize as a plain employee (no services.view_financial).
        [$employee] = $this->makeStaff();
        Auth::login($employee);

        $array = $service->fresh()->toArray();
        $this->assertArrayNotHasKey('default_price_usd', $array);
        $this->assertArrayNotHasKey('estimated_cost_ils', $array);
        $this->assertArrayNotHasKey('tax_rate', $array);
    }

    public function test_project_manager_view_does_not_expose_financial_fields(): void
    {
        $service = $this->makeService();

        $pm = $this->makeUser(RoleName::ProjectManager);
        Auth::login($pm);

        // PM has services.view but NOT services.view_financial.
        $this->assertTrue($pm->can('services.view'));
        $this->assertFalse($pm->can('services.view_financial'));

        $array = $service->fresh()->toArray();
        $this->assertArrayNotHasKey('default_price_usd', $array);
        $this->assertArrayNotHasKey('estimated_cost_ils', $array);
    }

    public function test_financial_user_can_see_price(): void
    {
        $service = $this->makeService();

        $accountant = $this->makeUser(RoleName::Accountant);
        Auth::login($accountant);

        $this->assertTrue($accountant->can('services.view_financial'));
        $array = $service->fresh()->toArray();
        $this->assertArrayHasKey('default_price_usd', $array);
    }

    public function test_price_change_creates_audit_log(): void
    {
        $service = $this->makeService(['default_price_usd' => 250]);

        app(ServiceCatalogService::class)->update($service, ['default_price_usd' => 400]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service_price_changed',
            'module' => 'Services',
            'auditable_id' => $service->id,
        ]);
    }

    public function test_cost_change_creates_audit_log(): void
    {
        $service = $this->makeService(['estimated_cost_ils' => 300]);

        app(ServiceCatalogService::class)->update($service, ['estimated_cost_ils' => 500]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service_cost_changed',
            'auditable_id' => $service->id,
        ]);
    }
}
