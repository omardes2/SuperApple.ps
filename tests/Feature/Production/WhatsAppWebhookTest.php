<?php

namespace Tests\Feature\Production;

use App\Enums\WhatsAppMessageStatus;
use App\Models\WhatsAppMessage;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The public Meta Cloud API webhook: GET verification handshake, POST delivery
 * status updates (idempotent, non-regressing) and inbound message recording.
 * The endpoint needs no app auth and is CSRF-exempt.
 */
class WhatsAppWebhookTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Settings::class)->set('whatsapp', 'meta_verify_token', 'my-verify-token', 'string');
    }

    private function outbound(string $wamid, WhatsAppMessageStatus $status = WhatsAppMessageStatus::Sent): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'phone' => '970599000000',
            'message_body' => 'فاتورتك',
            'provider' => 'meta_cloud',
            'provider_message_id' => $wamid,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => $status,
            'sent_at' => now(),
        ]);
    }

    private function statusPayload(string $wamid, string $state, array $extra = []): array
    {
        return ['entry' => [['changes' => [['value' => [
            'statuses' => [array_merge(['id' => $wamid, 'status' => $state, 'timestamp' => (string) now()->timestamp], $extra)],
        ]]]]]];
    }

    // ------------------------------------------------------ Verification (GET)

    public function test_verification_echoes_challenge_when_token_matches(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=my-verify-token&hub.challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_verification_rejects_wrong_token(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=WRONG&hub.challenge=12345')
            ->assertForbidden();
    }

    // ------------------------------------------------------------- Status (POST)

    public function test_delivered_then_read_updates_message(): void
    {
        $m = $this->outbound('wamid.1');

        $this->postJson('/webhooks/whatsapp', $this->statusPayload('wamid.1', 'delivered'))->assertOk();
        $this->assertSame(WhatsAppMessageStatus::Delivered, $m->fresh()->status);
        $this->assertNotNull($m->fresh()->delivered_at);

        $this->postJson('/webhooks/whatsapp', $this->statusPayload('wamid.1', 'read'))->assertOk();
        $this->assertSame(WhatsAppMessageStatus::Read, $m->fresh()->status);
        $this->assertNotNull($m->fresh()->read_at);
    }

    public function test_late_delivered_does_not_regress_a_read_message(): void
    {
        $m = $this->outbound('wamid.2', WhatsAppMessageStatus::Read);

        $this->postJson('/webhooks/whatsapp', $this->statusPayload('wamid.2', 'delivered'))->assertOk();

        $this->assertSame(WhatsAppMessageStatus::Read, $m->fresh()->status); // unchanged
    }

    public function test_failed_status_records_reason(): void
    {
        $m = $this->outbound('wamid.3');

        $this->postJson('/webhooks/whatsapp', $this->statusPayload('wamid.3', 'failed', [
            'errors' => [['title' => 'Message undeliverable']],
        ]))->assertOk();

        $fresh = $m->fresh();
        $this->assertSame(WhatsAppMessageStatus::Failed, $fresh->status);
        $this->assertSame('Message undeliverable', $fresh->failure_reason);
    }

    public function test_unknown_message_id_is_ignored_gracefully(): void
    {
        $this->postJson('/webhooks/whatsapp', $this->statusPayload('wamid.unknown', 'delivered'))->assertOk();
        $this->assertSame(0, WhatsAppMessage::count());
    }

    // ------------------------------------------------------------ Inbound (POST)

    public function test_inbound_message_is_recorded_once(): void
    {
        $payload = ['entry' => [['changes' => [['value' => [
            'messages' => [[
                'id' => 'wamid.in1', 'from' => '970599254870', 'type' => 'text',
                'text' => ['body' => 'مرحباً، بدي أستفسر'],
            ]],
        ]]]]]];

        $this->postJson('/webhooks/whatsapp', $payload)->assertOk();
        $this->postJson('/webhooks/whatsapp', $payload)->assertOk(); // retry — idempotent

        $rows = WhatsAppMessage::where('direction', WhatsAppMessage::DIRECTION_INBOUND)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('مرحباً، بدي أستفسر', $rows->first()->message_body);
    }

    // --------------------------------------------------------------- Signature

    public function test_invalid_signature_is_rejected_when_app_secret_set(): void
    {
        app(Settings::class)->set('whatsapp', 'meta_app_secret', 'shhh', 'string');
        $this->outbound('wamid.4');

        // No / wrong signature header → 403, message untouched.
        $this->withHeaders(['X-Hub-Signature-256' => 'sha256=deadbeef'])
            ->postJson('/webhooks/whatsapp', $this->statusPayload('wamid.4', 'delivered'))
            ->assertForbidden();

        $this->assertSame(WhatsAppMessageStatus::Sent, WhatsAppMessage::first()->status);
    }

    public function test_valid_signature_is_accepted(): void
    {
        app(Settings::class)->set('whatsapp', 'meta_app_secret', 'shhh', 'string');
        $m = $this->outbound('wamid.5');

        $payload = $this->statusPayload('wamid.5', 'delivered');
        $raw = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $raw, 'shhh');

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Hub-Signature-256' => $sig,
        ], $raw)->assertOk();

        $this->assertSame(WhatsAppMessageStatus::Delivered, $m->fresh()->status);
    }
}
