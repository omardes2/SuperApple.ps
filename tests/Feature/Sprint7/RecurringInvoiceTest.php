<?php

namespace Tests\Feature\Sprint7;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Exceptions\IssuedInvoiceImmutableException;
use App\Models\Invoice;
use App\Models\SubscriptionBilling;
use App\Models\User;
use App\Notifications\SubscriptionAutoIssueFailed;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $gm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($this->gm);
    }

    private function billing(): SubscriptionBillingService
    {
        return app(SubscriptionBillingService::class);
    }

    public function test_billing_generates_a_draft_invoice_linked_to_subscription(): void
    {
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false]);
        $result = $this->billing()->billOne($sub->id, '2026-08-01');

        $this->assertSame('generated', $result['outcome']);
        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($sub->id, $invoice->subscription_id);
    }

    public function test_recurring_invoice_copies_items_and_customer(): void
    {
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false], [
            ['item_name' => 'بند أ', 'quantity' => 2, 'unit_price_usd' => '100', 'tax_rate' => 0],
        ]);
        $this->billing()->billOne($sub->id, '2026-08-01');
        $invoice = Invoice::where('subscription_id', $sub->id)->first();

        $this->assertSame($sub->customer_id, $invoice->customer_id);
        $this->assertSame('بند أ', $invoice->items->first()->item_name);
        $this->assertSame('200.00', $invoice->total_usd);
        $this->assertSame('USD', $invoice->currency);
    }

    public function test_auto_issue_posts_gl_and_snapshots_rate(): void
    {
        $this->seedExchangeRate('2026-08-01', '3.60');
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => true, 'start_date' => '2026-08-01']);
        $result = $this->billing()->billOne($sub->id, '2026-08-01');

        $this->assertSame('issued', $result['outcome']);
        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('3.600000', $invoice->exchange_rate);
        // Issuing a recurring invoice posts the standard GL journal.
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'invoice', 'source_id' => $invoice->id, 'posting_type' => 'invoice_issue']);
    }

    public function test_auto_issue_without_rate_leaves_draft_and_notifies(): void
    {
        Notification::fake();
        // No exchange rate seeded → cannot issue.
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => true, 'start_date' => '2026-08-01']);
        $result = $this->billing()->billOne($sub->id, '2026-08-01');

        $this->assertSame('generated', $result['outcome']); // draft, not issued
        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $billing = SubscriptionBilling::where('subscription_id', $sub->id)->first();
        $this->assertNotNull($billing->error_message);
        Notification::assertSentTo($this->gm, SubscriptionAutoIssueFailed::class);
    }

    public function test_next_billing_date_advances_only_after_generation(): void
    {
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false, 'start_date' => '2026-08-01']);
        $this->assertSame('2026-08-01', $sub->next_billing_date->toDateString());
        $this->billing()->billOne($sub->id, '2026-08-01');
        $this->assertSame('2026-09-01', $sub->fresh()->next_billing_date->toDateString());
        $this->assertNotNull($sub->fresh()->last_billed_at);
    }

    public function test_issued_recurring_invoice_is_immutable(): void
    {
        $this->seedExchangeRate('2026-08-01', '3.60');
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => true, 'start_date' => '2026-08-01']);
        $this->billing()->billOne($sub->id, '2026-08-01');
        $invoice = Invoice::where('subscription_id', $sub->id)->first();

        $this->expectException(IssuedInvoiceImmutableException::class);
        $invoice->update(['total_usd' => '1']);
    }

    public function test_not_due_before_start_date(): void
    {
        $sub = $this->makeActiveSubscription(null, ['start_date' => '2026-12-01']);
        $result = $this->billing()->billOne($sub->id, '2026-08-01');
        $this->assertSame('skipped', $result['outcome']);
        $this->assertSame(0, Invoice::where('subscription_id', $sub->id)->count());
    }

    public function test_expires_when_period_past_end_date(): void
    {
        $sub = $this->makeActiveSubscription(null, ['start_date' => '2026-08-01', 'end_date' => '2026-08-15']);
        // First period Aug 1–31 already passes end date after billing → expires.
        $this->billing()->billOne($sub->id, '2026-08-01');
        $this->assertSame('expired', $sub->fresh()->status->value);
    }

    public function test_run_due_only_bills_active_due_auto_generate_subscriptions(): void
    {
        $active = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false, 'start_date' => '2026-08-01']);
        $manual = $this->makeActiveSubscription(null, ['auto_generate_invoice' => false, 'start_date' => '2026-08-01']);

        $summary = $this->billing()->runDue('2026-08-01');
        $this->assertSame(1, $summary['generated']);
        $this->assertSame(1, Invoice::where('subscription_id', $active->id)->count());
        $this->assertSame(0, Invoice::where('subscription_id', $manual->id)->count());
    }

    public function test_paused_subscription_is_not_billed(): void
    {
        $sub = $this->makeActiveSubscription(null, ['start_date' => '2026-08-01']);
        app(SubscriptionService::class)->pause($sub->fresh());
        $result = $this->billing()->billOne($sub->id, '2026-08-01');
        $this->assertSame('skipped', $result['outcome']);
    }

    public function test_billing_log_records_period(): void
    {
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => false, 'start_date' => '2026-08-01']);
        $this->billing()->billOne($sub->id, '2026-08-01');
        $billing = SubscriptionBilling::where('subscription_id', $sub->id)->first();
        $this->assertSame('2026-08-01', $billing->period_start->toDateString());
        $this->assertSame('2026-08-31', $billing->period_end->toDateString());
    }
}
