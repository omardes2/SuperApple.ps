<?php

namespace Tests\Feature\Sprint7;

use App\Enums\RoleName;
use App\Models\WhatsAppMessage;
use App\Services\PaymentReminderService;
use App\Services\Settings;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ManualReminderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(WhatsAppSeeder::class);
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function service(): PaymentReminderService
    {
        return app(PaymentReminderService::class);
    }

    public function test_context_reports_outstanding_figures(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $this->makeIssuedInvoice($customer, '500', '3.20');
        $this->makeIssuedInvoice($customer, '300', '3.20');

        $ctx = $this->service()->manualContext($customer);
        $this->assertSame('800.00', $ctx['outstanding_usd']);
        $this->assertSame('800.00', $ctx['net_balance_usd']);
        $this->assertStringContainsString('USD', $ctx['invoice_list']);
    }

    public function test_default_manual_body_includes_company_and_amount(): void
    {
        app(Settings::class)->set('company', 'name', 'سوبر آبل', 'string');
        $customer = $this->makeCustomer(['name' => 'بلوتو براند', 'whatsapp_number' => '0591234567']);
        $this->makeIssuedInvoice($customer, '500', '3.20');

        $body = $this->service()->defaultManualBody($customer);

        $this->assertStringContainsString('بلوتو براند', $body); // customer
        $this->assertStringContainsString('سوبر آبل', $body);      // company
        $this->assertStringContainsString('500.00', $body);         // outstanding amount
        $this->assertStringContainsString('USD', $body);
    }

    public function test_no_central_rate_means_no_ils_estimate_in_reminders(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $this->makeIssuedInvoice($customer, '500', '3.20');
        // Even if a legacy exchange-rate row exists, reminders never read a central rate.
        $this->seedExchangeRate(now()->toDateString(), '4.00');

        $ctx = $this->service()->manualContext($customer);
        // The standalone exchange-rate module was retired: USD is the only figure.
        $this->assertNull($ctx['estimated_ils']);
        $this->assertNull($ctx['latest_rate']);
        $this->assertSame('500.00', $ctx['net_balance_usd']);
    }

    public function test_balance_variables_include_required_keys(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $this->makeIssuedInvoice($customer, '500', '3.20');

        $vars = $this->service()->balanceVariables($customer);
        foreach (['customer_name', 'balance_usd', 'balance_ils', 'invoice_list'] as $key) {
            $this->assertArrayHasKey($key, $vars);
        }
        $this->assertSame('500.00', $vars['balance_usd']);
    }

    public function test_send_manual_reminder_queues_a_message(): void
    {
        $fake = $this->useFakeWhatsApp();
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $this->makeIssuedInvoice($customer, '500', '3.20');

        $message = $this->service()->sendManualReminder($customer);

        $this->assertInstanceOf(WhatsAppMessage::class, $message);
        $this->assertSame(1, $fake->count());
        $this->assertStringContainsString('500.00', $fake->lastBody());
    }

    public function test_manual_reminder_accepts_custom_body(): void
    {
        $this->useFakeWhatsApp();
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $this->makeIssuedInvoice($customer, '500', '3.20');

        $message = $this->service()->sendManualReminder($customer, 'رصيدك {{balance_usd}} دولار');
        $this->assertStringContainsString('500.00', $message->message_body);
    }

    public function test_default_manual_template_exists(): void
    {
        $this->assertNotNull($this->service()->defaultManualTemplate());
    }
}
