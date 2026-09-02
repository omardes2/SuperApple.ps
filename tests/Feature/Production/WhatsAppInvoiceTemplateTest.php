<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\WhatsAppMessageStatus;
use App\Livewire\Admin\WhatsAppDashboard;
use App\Models\WhatsAppMessage;
use App\Services\Settings;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Invoice delivery over the Meta Cloud API. When an approved template is
 * configured the invoice is sent as that template (text only, a single {{1}}
 * body parameter) so it reaches the customer reliably as a business-initiated
 * message; with no template configured it falls back to the PDF document send.
 * All network is faked — no real call ever leaves the test.
 */
class WhatsAppInvoiceTemplateTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles(); // roles + chart of accounts (issuing an invoice posts GL)
    }

    private function configureMeta(bool $withTemplate): void
    {
        $s = app(Settings::class);
        $s->set('whatsapp', 'enabled', true, 'bool');
        $s->set('whatsapp', 'provider', 'meta_cloud', 'string');
        $s->set('whatsapp', 'default_country_code', '970', 'string');
        $s->set('whatsapp', 'meta_phone_number_id', '100000000000001', 'string');
        $s->set('whatsapp', 'meta_access_token', 'EAAG-test-token', 'string');
        $s->set('whatsapp', 'meta_api_version', 'v21.0', 'string');
        if ($withTemplate) {
            $s->set('whatsapp', 'meta_invoice_template', 'superapple_notify', 'string');
            $s->set('whatsapp', 'meta_template_language', 'ar', 'string');
        }
    }

    public function test_invoice_send_uses_template_when_configured(): void
    {
        $this->configureMeta(withTemplate: true);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL']]], 200),
        ]);

        $customer = $this->makeCustomer(['name' => 'بلوتو براند', 'whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '1000');

        $message = app(WhatsAppService::class)->sendInvoice($invoice)->fresh();

        $this->assertSame(WhatsAppMessageStatus::Sent, $message->status);
        $this->assertSame('wamid.TPL', $message->provider_message_id);
        $this->assertSame('meta_cloud', $message->provider);
        $this->assertNull($message->document_name); // template send carries no PDF

        Http::assertSent(function ($request) {
            $tpl = $request['template'] ?? [];

            return str_contains($request->url(), '/100000000000001/messages')
                && $request['type'] === 'template'
                && ($tpl['name'] ?? null) === 'superapple_notify'
                && ($tpl['language']['code'] ?? null) === 'ar'
                && count($tpl['components'][0]['parameters'] ?? []) === 1;
        });
    }

    public function test_template_parameter_is_single_line_with_invoice_facts(): void
    {
        $this->configureMeta(withTemplate: true);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        $customer = $this->makeCustomer(['name' => 'بلوتو براند', 'whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '1000');

        app(WhatsAppService::class)->sendInvoice($invoice);

        Http::assertSent(function ($request) use ($invoice) {
            $param = $request['template']['components'][0]['parameters'][0]['text'] ?? '';

            return $param !== ''
                && ! str_contains($param, "\n")        // Meta rejects newlines in params
                && str_contains($param, 'بلوتو براند')
                && str_contains($param, $invoice->invoice_number);
        });
    }

    public function test_variable_helper_flattens_whitespace(): void
    {
        $customer = $this->makeCustomer(['name' => "شركة\n\tالأمل", 'whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '500');

        $var = app(WhatsAppService::class)->invoiceTemplateVariable($invoice);

        $this->assertStringNotContainsString("\n", $var);
        $this->assertStringNotContainsString("\t", $var);
        $this->assertStringContainsString($invoice->invoice_number, $var);
    }

    public function test_without_template_falls_back_to_document(): void
    {
        $this->configureMeta(withTemplate: false);
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-9'], 200),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.DOC']]], 200),
        ]);

        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '500');

        $message = app(WhatsAppService::class)->sendInvoice($invoice)->fresh();

        $this->assertSame(WhatsAppMessageStatus::Sent, $message->status);
        $this->assertNotNull($message->document_name);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/media'));
    }

    public function test_queued_message_delivers_via_template_when_configured(): void
    {
        // A payment reminder / notification goes through the queue → deliver()
        // path (sendText historically). With a template configured it must be
        // delivered as that template so it reaches the customer reliably.
        $this->configureMeta(withTemplate: true);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.Q']]], 200),
        ]);

        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $message = WhatsAppMessage::create([
            'customer_id' => $customer->id,
            'phone' => '970599432037',
            'message_body' => "تذكير دفع مستحق\nالفاتورة INV-1\nالمبلغ 100 USD",
            'provider' => 'meta_cloud',
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessageStatus::Pending,
        ]);

        app(WhatsAppService::class)->deliver($message);

        $this->assertSame(WhatsAppMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame('wamid.Q', $message->fresh()->provider_message_id);

        Http::assertSent(function ($request) {
            $param = $request['template']['components'][0]['parameters'][0]['text'] ?? '';

            return ($request['type'] ?? null) === 'template'
                && $param !== ''
                && ! str_contains($param, "\n"); // flattened to one line for Meta
        });
    }

    public function test_settings_ui_persists_template_name_and_language(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));

        Livewire::test(WhatsAppDashboard::class)
            ->set('provider', 'meta_cloud')
            ->set('enabled', true)
            ->set('metaPhoneNumberId', '100000000000001')
            ->set('metaAccessToken', 'EAAG-secret')
            ->set('metaInvoiceTemplate', 'superapple_notify')
            ->set('metaTemplateLanguage', 'ar')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $s = app(Settings::class);
        $this->assertSame('superapple_notify', $s->get('whatsapp', 'meta_invoice_template'));
        $this->assertSame('ar', $s->get('whatsapp', 'meta_template_language'));
    }
}
