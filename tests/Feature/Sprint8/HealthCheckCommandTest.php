<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class HealthCheckCommandTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_health_check_passes_on_a_seeded_healthy_database(): void
    {
        // Minimal live data so the accounting/reconciliation blocks exercise real rows.
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '500', '3.20');

        $this->artisan('app:health-check')->assertExitCode(0);
    }

    public function test_health_check_never_leaks_the_app_key_value(): void
    {
        $sentinel = 'base64:SUPERSECRETKEYVALUE_MUST_NOT_LEAK_1234567890abcdef==';
        config(['app.key' => $sentinel]);

        $exit = Artisan::call('app:health-check');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('app.key', $output);
        $this->assertStringNotContainsString($sentinel, $output);
        $this->assertStringNotContainsString('SUPERSECRETKEYVALUE', $output);
    }

    public function test_health_check_json_option_emits_valid_secret_free_summary(): void
    {
        $sentinel = 'base64:SUPERSECRETKEYVALUE_MUST_NOT_LEAK_1234567890abcdef==';
        config(['app.key' => $sentinel]);

        $exit = Artisan::call('app:health-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString($sentinel, $output);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(0, $decoded['counts']['fail']);
    }
}
