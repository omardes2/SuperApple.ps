<?php

namespace Tests\Feature\Sprint7;

use App\Enums\RoleName;
use App\Livewire\Admin\SubscriptionsIndex;
use App\Livewire\Admin\WhatsAppDashboard;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SubscriptionSecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_employee_has_no_subscription_or_whatsapp_access(): void
    {
        [$user] = $this->makeStaff();
        foreach (['subscriptions.view', 'subscriptions.create', 'whatsapp.view', 'whatsapp.send'] as $perm) {
            $this->assertFalse($user->can($perm), "employee must not have [{$perm}]");
        }
    }

    public function test_hr_has_no_subscription_access(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);
        $this->assertFalse($hr->can('subscriptions.view'));
        $this->assertFalse($hr->can('whatsapp.view'));
    }

    public function test_project_manager_sees_subscriptions_but_not_prices(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        $this->assertTrue($pm->can('subscriptions.view'));
        // No pricing/financial visibility, even as a project member.
        $this->assertFalse($pm->can('viewPrices', Subscription::class));
        $this->assertFalse($pm->can('subscriptions.create'));
        $this->assertFalse($pm->can('subscriptions.bill'));
    }

    public function test_accountant_can_bill_but_not_manage_channel_settings(): void
    {
        $acc = $this->makeUser(RoleName::Accountant);
        $this->assertTrue($acc->can('subscriptions.view'));
        $this->assertTrue($acc->can('subscriptions.bill'));
        $this->assertTrue($acc->can('whatsapp.send'));
        $this->assertTrue($acc->can('viewPrices', Subscription::class));
        // Channel/provider configuration is reserved for the GM.
        $this->assertFalse($acc->can('whatsapp.settings.manage'));
        $this->assertFalse($acc->can('whatsapp.templates.manage'));
    }

    public function test_general_manager_has_full_subscription_and_whatsapp(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        foreach ([
            'subscriptions.view', 'subscriptions.create', 'subscriptions.bill', 'subscriptions.cancel',
            'whatsapp.view', 'whatsapp.send', 'whatsapp.templates.manage', 'whatsapp.settings.manage',
            'whatsapp.reminders.manage',
        ] as $perm) {
            $this->assertTrue($gm->can($perm), "GM must have [{$perm}]");
        }
    }

    public function test_employee_cannot_open_subscriptions_or_whatsapp_pages(): void
    {
        [$user] = $this->makeStaff();
        Livewire::actingAs($user)->test(SubscriptionsIndex::class)->assertForbidden();
        Livewire::actingAs($user)->test(WhatsAppDashboard::class)->assertForbidden();
    }

    public function test_pm_index_hides_price_column(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);
        Livewire::actingAs($pm)->test(SubscriptionsIndex::class)
            ->assertViewHas('canPrices', false)
            ->assertViewHas('canReports', false);
    }
}
