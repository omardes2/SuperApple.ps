<?php

namespace Tests\Feature\Sprint0;

use App\Enums\RoleName;
use App\Livewire\Admin\SettingsPage;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_settings_service_stores_typed_values(): void
    {
        $settings = app(Settings::class);

        $settings->set('finance', 'default_exchange_rate', '3.28', 'decimal');
        $settings->set('whatsapp', 'enabled', true, 'bool');
        $settings->set('attendance', 'work_days', ['sun', 'mon'], 'json');

        $this->assertSame(3.28, $settings->get('finance', 'default_exchange_rate'));
        $this->assertTrue($settings->get('whatsapp', 'enabled'));
        $this->assertSame(['sun', 'mon'], $settings->get('attendance', 'work_days'));
        $this->assertSame('fallback', $settings->get('missing', 'key', 'fallback'));
    }

    public function test_manager_can_save_settings_and_it_is_audited(): void
    {
        $manager = $this->makeUser(RoleName::GeneralManager);

        Livewire::actingAs($manager)
            ->test(SettingsPage::class)
            ->set('companyName', 'وكالة سوبر آبل')
            ->set('defaultExchangeRate', '3.31')
            ->set('workStart', '08:30')
            ->set('workEnd', '16:30')
            ->set('graceMinutes', 10)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(Settings::class);
        $settings->flush();
        $this->assertSame('وكالة سوبر آبل', $settings->get('company', 'name'));
        $this->assertSame(3.31, $settings->get('finance', 'default_exchange_rate'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'settings_updated', 'module' => 'Settings']);
    }

    public function test_employee_cannot_mount_settings_component(): void
    {
        $employee = $this->makeUser(RoleName::Employee);

        Livewire::actingAs($employee)
            ->test(SettingsPage::class)
            ->assertForbidden();
    }
}
