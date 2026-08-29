<?php

namespace Tests\Feature\Sprint7;

use App\Enums\RoleName;
use App\Models\Invoice;
use App\Models\SubscriptionBilling;
use App\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class BillingDuplicateAndDryRunTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function billing(): SubscriptionBillingService
    {
        return app(SubscriptionBillingService::class);
    }

    public function test_same_period_is_never_billed_twice(): void
    {
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false, 'start_date' => '2026-08-01']);
        $this->billing()->billOne($sub->id, '2026-08-01');
        // Reset next_billing back to the same period to simulate a re-run race.
        $sub->update(['next_billing_date' => '2026-08-01']);
        $result = $this->billing()->billOne($sub->id, '2026-08-01');

        $this->assertSame('skipped', $result['outcome']);
        $this->assertSame(1, Invoice::where('subscription_id', $sub->id)->count());
        $this->assertSame(1, SubscriptionBilling::where('subscription_id', $sub->id)->count());
    }

    public function test_running_twice_on_same_day_does_not_duplicate(): void
    {
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false, 'start_date' => '2026-08-01']);
        $this->billing()->runDue('2026-08-01');
        $this->billing()->runDue('2026-08-01');
        $this->assertSame(1, Invoice::where('subscription_id', $sub->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false, 'start_date' => '2026-08-01']);
        $before = $sub->next_billing_date->toDateString();

        $summary = $this->billing()->runDue('2026-08-01', dryRun: true);

        $this->assertSame(1, $summary['generated']);
        $this->assertSame(0, Invoice::where('subscription_id', $sub->id)->count());
        $this->assertSame(0, SubscriptionBilling::where('subscription_id', $sub->id)->count());
        $this->assertSame($before, $sub->fresh()->next_billing_date->toDateString());
    }
}
