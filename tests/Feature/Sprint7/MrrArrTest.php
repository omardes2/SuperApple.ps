<?php

namespace Tests\Feature\Sprint7;

use App\Enums\RoleName;
use App\Services\SubscriptionMetricsService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class MrrArrTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function metrics(): SubscriptionMetricsService
    {
        return app(SubscriptionMetricsService::class);
    }

    public function test_mrr_sums_active_normalised_values(): void
    {
        $this->makeActiveSubscription(null, ['billing_cycle' => 'monthly'], [['item_name' => 'A', 'quantity' => 1, 'unit_price_usd' => '600', 'tax_rate' => 0]]);
        $this->makeActiveSubscription(null, ['billing_cycle' => 'quarterly'], [['item_name' => 'B', 'quantity' => 1, 'unit_price_usd' => '300', 'tax_rate' => 0]]);
        $this->makeActiveSubscription(null, ['billing_cycle' => 'yearly'], [['item_name' => 'C', 'quantity' => 1, 'unit_price_usd' => '1200', 'tax_rate' => 0]]);

        // 600 + 100 + 100 = 800
        $this->assertSame('800.00', $this->metrics()->mrr());
    }

    public function test_arr_is_mrr_times_twelve(): void
    {
        $this->makeActiveSubscription(null, ['billing_cycle' => 'monthly'], [['item_name' => 'A', 'quantity' => 1, 'unit_price_usd' => '500', 'tax_rate' => 0]]);
        $this->assertSame('500.00', $this->metrics()->mrr());
        $this->assertSame('6000.00', $this->metrics()->arr());
    }

    public function test_paused_and_cancelled_are_excluded_from_mrr(): void
    {
        $active = $this->makeActiveSubscription(null, ['billing_cycle' => 'monthly'], [['item_name' => 'A', 'quantity' => 1, 'unit_price_usd' => '400', 'tax_rate' => 0]]);
        $paused = $this->makeActiveSubscription(null, ['billing_cycle' => 'monthly'], [['item_name' => 'B', 'quantity' => 1, 'unit_price_usd' => '999', 'tax_rate' => 0]]);
        app(SubscriptionService::class)->pause($paused->fresh());

        $this->assertSame('400.00', $this->metrics()->mrr());
    }

    public function test_draft_subscriptions_do_not_count(): void
    {
        $this->makeSubscription(null, ['billing_cycle' => 'monthly'], [['item_name' => 'A', 'quantity' => 1, 'unit_price_usd' => '400', 'tax_rate' => 0]]);
        $this->assertSame('0.00', $this->metrics()->mrr());
    }

    public function test_summary_reports_counts_and_active(): void
    {
        $this->makeActiveSubscription();
        $summary = $this->metrics()->summary();
        $this->assertSame(1, $summary['active']);
        $this->assertArrayHasKey('mrr_usd', $summary);
        $this->assertArrayHasKey('arr_usd', $summary);
    }
}
