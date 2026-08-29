<?php

namespace Tests\Feature\Sprint7;

use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Models\Service;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_subscription_number_is_generated(): void
    {
        $sub = $this->makeSubscription();
        $this->assertMatchesRegularExpression('/^SUB-\d{4}-\d{4}$/', $sub->subscription_number);
    }

    public function test_new_subscription_is_draft_with_no_next_billing(): void
    {
        $sub = $this->makeSubscription();
        $this->assertSame(SubscriptionStatus::Draft, $sub->status);
        $this->assertNull($sub->next_billing_date);
    }

    public function test_totals_are_computed_from_items(): void
    {
        $sub = $this->makeSubscription(null, [], [
            ['item_name' => 'A', 'quantity' => 2, 'unit_price_usd' => '100', 'tax_rate' => 0],
            ['item_name' => 'B', 'quantity' => 1, 'unit_price_usd' => '50', 'tax_rate' => 10],
        ]);
        // 200 + (50 + 5 tax) = 255
        $this->assertSame('255.00', $sub->total_usd);
    }

    public function test_activation_seeds_next_billing_to_start_date(): void
    {
        $sub = $this->makeSubscription(null, ['start_date' => '2026-09-01']);
        app(SubscriptionService::class)->activate($sub);
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
        $this->assertSame('2026-09-01', $sub->fresh()->next_billing_date->toDateString());
    }

    public function test_cannot_activate_without_items(): void
    {
        $sub = $this->makeSubscription();
        $sub->items()->delete();
        $this->expectException(RuntimeException::class);
        app(SubscriptionService::class)->activate($sub->fresh());
    }

    public function test_pause_and_resume_lifecycle(): void
    {
        $sub = $this->makeActiveSubscription();
        app(SubscriptionService::class)->pause($sub->fresh());
        $this->assertSame(SubscriptionStatus::Paused, $sub->fresh()->status);

        app(SubscriptionService::class)->resume($sub->fresh(), '2026-12-01');
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
        $this->assertSame('2026-12-01', $sub->fresh()->next_billing_date->toDateString());
    }

    public function test_resume_never_backdates_next_billing(): void
    {
        $sub = $this->makeActiveSubscription();
        app(SubscriptionService::class)->pause($sub->fresh());
        // A past resume date is clamped to today (no backdated invoices).
        app(SubscriptionService::class)->resume($sub->fresh(), '2000-01-01');
        $this->assertTrue($sub->fresh()->next_billing_date->gte(now()->startOfDay()));
    }

    public function test_cancel_keeps_record_and_stops_billing(): void
    {
        $sub = $this->makeActiveSubscription();
        app(SubscriptionService::class)->cancel($sub->fresh(), 'انتهاء التعاقد');
        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $sub->status);
        $this->assertNull($sub->next_billing_date);
        $this->assertSame('انتهاء التعاقد', $sub->cancellation_reason);
        $this->assertNotNull($sub->cancelled_at);
    }

    public function test_cancel_requires_reason(): void
    {
        $sub = $this->makeActiveSubscription();
        $this->expectException(RuntimeException::class);
        app(SubscriptionService::class)->cancel($sub->fresh(), '');
    }

    public function test_cannot_pause_a_draft(): void
    {
        $sub = $this->makeSubscription();
        $this->expectException(RuntimeException::class);
        app(SubscriptionService::class)->pause($sub);
    }

    public function test_price_snapshot_is_independent_of_catalog(): void
    {
        $service = Service::create(['service_code' => 'SRV-T1', 'name' => 'خدمة', 'service_type' => 'monthly', 'default_price_usd' => '100', 'tax_rate' => 0, 'is_active' => true]);
        $sub = $this->makeSubscription(null, [], [
            ['service_id' => $service->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '100', 'tax_rate' => 0],
        ]);
        // Catalog price changes later — the subscription item keeps its snapshot.
        $service->update(['default_price_usd' => '999']);
        $this->assertSame('100.0000', $sub->fresh()->items->first()->unit_price_usd);
        $this->assertSame('100.00', $sub->fresh()->total_usd);
    }

    public function test_monthly_mrr_equals_total(): void
    {
        $sub = $this->makeSubscription(null, ['billing_cycle' => 'monthly'], [
            ['item_name' => 'A', 'quantity' => 1, 'unit_price_usd' => '300', 'tax_rate' => 0],
        ]);
        $this->assertSame('300.00', $sub->monthlyRecurringRevenue());
    }

    public function test_quarterly_mrr_is_third(): void
    {
        $sub = $this->makeSubscription(null, ['billing_cycle' => 'quarterly'], [
            ['item_name' => 'A', 'quantity' => 1, 'unit_price_usd' => '300', 'tax_rate' => 0],
        ]);
        $this->assertSame('100.00', $sub->monthlyRecurringRevenue());
    }

    public function test_yearly_mrr_is_twelfth(): void
    {
        $sub = $this->makeSubscription(null, ['billing_cycle' => 'yearly'], [
            ['item_name' => 'A', 'quantity' => 1, 'unit_price_usd' => '1200', 'tax_rate' => 0],
        ]);
        $this->assertSame('100.00', $sub->monthlyRecurringRevenue());
    }

    public function test_billing_interval_widens_the_period(): void
    {
        // Monthly, every 2 months → next billing is start + 2 months after one bill.
        $sub = $this->makeActiveSubscription(null, ['billing_cycle' => 'monthly', 'billing_interval' => 2, 'start_date' => '2026-08-01']);
        app(SubscriptionBillingService::class)->billOne($sub->id, '2026-08-01');
        $this->assertSame('2026-10-01', $sub->fresh()->next_billing_date->toDateString());
    }

    public function test_updating_draft_recomputes_totals(): void
    {
        $sub = $this->makeSubscription();
        app(SubscriptionService::class)->update($sub, ['name' => 'محدّث'], [
            ['item_name' => 'X', 'quantity' => 1, 'unit_price_usd' => '750', 'tax_rate' => 0],
        ]);
        $this->assertSame('750.00', $sub->fresh()->total_usd);
        $this->assertSame('محدّث', $sub->fresh()->name);
    }
}
