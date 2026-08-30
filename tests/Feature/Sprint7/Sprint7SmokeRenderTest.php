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

    public function test_subscription_pages_are_gone(): void
    {
        // The subscriptions module was retired — its routes no longer exist.
        $this->get('/admin/subscriptions')->assertNotFound();
    }

    public function test_whatsapp_pages_render(): void
    {
        $this->get('/admin/whatsapp')->assertOk();
        $this->get('/admin/whatsapp/templates')->assertOk();
        $this->get('/admin/whatsapp/reminders')->assertOk();
    }

    public function test_customer_profile_with_legacy_subscription_renders(): void
    {
        // A customer that carries legacy subscription data still opens cleanly,
        // even though the profile no longer shows a subscriptions tab.
        $customer = $this->makeCustomer();
        $this->makeActiveSubscription($customer);
        $this->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertDontSee('الاشتراكات');
    }

    public function test_invoice_with_legacy_subscription_id_renders_without_error(): void
    {
        // Legacy recurring invoices (subscription_id set) must still render; the
        // subscription name is shown as an archival note, never a broken link.
        $this->seedExchangeRate('2026-08-01', '3.60');
        $sub = $this->makeActiveSubscription(null, ['auto_issue_invoice' => true, 'start_date' => '2026-08-01']);
        app(SubscriptionBillingService::class)->billOne($sub->id, '2026-08-01');
        $invoice = Invoice::where('subscription_id', $sub->id)->first();

        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee($sub->subscription_number);
    }

    public function test_reminders_command_runs(): void
    {
        $this->artisan('payments:send-reminders --dry-run')->assertExitCode(0);
    }
}
