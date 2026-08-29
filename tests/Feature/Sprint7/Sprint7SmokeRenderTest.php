<?php

namespace Tests\Feature\Sprint7;

use App\Enums\RoleName;
use App\Models\Invoice;
use App\Services\SubscriptionBillingService;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class Sprint7SmokeRenderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(WhatsAppSeeder::class);
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_subscription_pages_render(): void
    {
        $sub = $this->makeActiveSubscription();
        $this->get('/admin/subscriptions')->assertOk()->assertSee('الاشتراكات');
        $this->get(route('admin.subscriptions.show', $sub))->assertOk()->assertSee($sub->subscription_number);
    }

    public function test_whatsapp_pages_render(): void
    {
        $this->get('/admin/whatsapp')->assertOk();
        $this->get('/admin/whatsapp/templates')->assertOk();
        $this->get('/admin/whatsapp/reminders')->assertOk();
    }

    public function test_customer_profile_subscriptions_and_communications_tabs_render(): void
    {
        $customer = $this->makeCustomer();
        $this->makeActiveSubscription($customer);
        $this->get(route('admin.customers.show', $customer))->assertOk();
    }

    public function test_recurring_invoice_shows_subscription_link(): void
    {
        $this->seedExchangeRate('2026-08-01', '3.60');
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => true, 'start_date' => '2026-08-01']);
        app(SubscriptionBillingService::class)->billOne($sub->id, '2026-08-01');
        $invoice = Invoice::where('subscription_id', $sub->id)->first();

        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee($sub->subscription_number);
    }

    public function test_dashboard_shows_subscription_cards_for_gm(): void
    {
        $this->makeActiveSubscription();
        $this->get('/admin')->assertOk()->assertSee('MRR');
    }

    public function test_billing_command_runs(): void
    {
        $this->makeActiveSubscription(null, ['auto_issue_invoice' => false, 'start_date' => now()->toDateString()]);
        $this->artisan('subscriptions:bill --dry-run')->assertExitCode(0);
        $this->artisan('subscriptions:bill')->assertExitCode(0);
    }

    public function test_reminders_command_runs(): void
    {
        $this->artisan('payments:send-reminders --dry-run')->assertExitCode(0);
    }
}
