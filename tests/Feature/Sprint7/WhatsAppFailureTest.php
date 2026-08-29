<?php

namespace Tests\Feature\Sprint7;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Enums\WhatsAppMessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class WhatsAppFailureTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(WhatsAppSeeder::class);
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_provider_failure_does_not_affect_the_issued_invoice(): void
    {
        $fake = $this->useFakeWhatsApp();
        $fake->fail('network down');

        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $invoice = $this->makeIssuedInvoice($customer, '500');

        // Notify runs the job synchronously; the provider throws.
        app(WhatsAppService::class)->notifyInvoiceIssued($invoice);

        // Invoice stays Issued; the message is Failed.
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $message = WhatsAppMessage::where('invoice_id', $invoice->id)->first();
        $this->assertSame(WhatsAppMessageStatus::Failed, $message->status);
        $this->assertNotNull($message->failure_reason);
    }

    public function test_successful_send_marks_message_sent(): void
    {
        $fake = $this->useFakeWhatsApp();
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $invoice = $this->makeIssuedInvoice($customer, '500');

        app(WhatsAppService::class)->notifyInvoiceIssued($invoice);

        $message = WhatsAppMessage::where('invoice_id', $invoice->id)->first();
        $this->assertSame(WhatsAppMessageStatus::Sent, $message->status);
        $this->assertSame(1, $fake->count());
    }

    public function test_message_is_dispatched_to_the_queue(): void
    {
        Queue::fake();
        $this->useFakeWhatsApp();
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $invoice = $this->makeIssuedInvoice($customer, '500');

        app(WhatsAppService::class)->notifyInvoiceIssued($invoice);

        Queue::assertPushed(SendWhatsAppMessageJob::class);
    }

    public function test_failed_message_can_be_retried(): void
    {
        $fake = $this->useFakeWhatsApp();
        $fake->fail();
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $invoice = $this->makeIssuedInvoice($customer, '500');
        app(WhatsAppService::class)->notifyInvoiceIssued($invoice);
        $message = WhatsAppMessage::where('invoice_id', $invoice->id)->first();
        $this->assertSame(WhatsAppMessageStatus::Failed, $message->status);

        // Now recover the provider and retry.
        $fake->succeed();
        app(WhatsAppService::class)->retry($message->fresh());
        $this->assertSame(WhatsAppMessageStatus::Sent, $message->fresh()->status);
    }

    public function test_disabled_channel_does_not_queue(): void
    {
        // WhatsApp disabled by default; automatic notify is a no-op.
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        $invoice = $this->makeIssuedInvoice($customer, '500');
        $result = app(WhatsAppService::class)->notifyInvoiceIssued($invoice);
        $this->assertNull($result);
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_invalid_phone_is_not_queued_for_automatic_flows(): void
    {
        $this->useFakeWhatsApp();
        $customer = $this->makeCustomer(['whatsapp_number' => null, 'phone' => 'abc']);
        $invoice = $this->makeIssuedInvoice($customer, '500');
        $result = app(WhatsAppService::class)->notifyInvoiceIssued($invoice);
        $this->assertNull($result);
        $this->assertSame(0, WhatsAppMessage::count());
    }
}
