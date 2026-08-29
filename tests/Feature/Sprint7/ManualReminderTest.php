<?php

namespace Tests\Feature\Sprint7;

use App\Enums\RoleName;
use App\Models\WhatsAppMessage;
use App\Services\PaymentReminderService;
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

    public function test_estimated_ils_uses_latest_rate_not_invoice_rate(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        // Invoice frozen at 3.20 …
        $this->makeIssuedInvoice($customer, '500', '3.20');
        // … but the latest market rate is 4.00.
        $this->seedExchangeRate(now()->toDateString(), '4.00');

        $ctx = $this->service()->manualContext($customer);
        // 500 × 4.00 = 2000, NOT 500 × 3.20.
        $this->assertSame('2000.00', $ctx['estimated_ils']);
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
