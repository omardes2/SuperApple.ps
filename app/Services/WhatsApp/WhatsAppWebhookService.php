<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppMessageStatus;
use App\Models\Customer;
use App\Models\WhatsAppMessage;
use App\Services\Settings;
use Illuminate\Support\Carbon;

/**
 * Handles inbound Meta WhatsApp Cloud API webhooks: the GET verification
 * handshake, optional payload-signature check, delivery-status updates
 * (sent → delivered → read, or failed), and recording inbound replies. All
 * lookups are by provider_message_id and every write is idempotent, so Meta's
 * at-least-once retries never double-apply.
 */
class WhatsAppWebhookService
{
    public function __construct(private readonly Settings $settings) {}

    /** The verify token the webhook GET handshake must echo. */
    public function verifyToken(): string
    {
        return (string) ($this->settings->get('whatsapp', 'meta_verify_token')
            ?: config('services.whatsapp.meta_verify_token') ?: '');
    }

    /** The Meta app secret used to sign payloads (optional). */
    public function appSecret(): string
    {
        return (string) ($this->settings->get('whatsapp', 'meta_app_secret')
            ?: config('services.whatsapp.meta_app_secret') ?: '');
    }

    /**
     * Validate Meta's X-Hub-Signature-256 header against the raw body. When no
     * app secret is configured the check is skipped (returns true) so the
     * webhook still works before the secret is set.
     */
    public function signatureValid(string $rawBody, ?string $header): bool
    {
        $secret = $this->appSecret();
        if ($secret === '') {
            return true;
        }
        if (! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header);
    }

    /**
     * Process a Cloud API webhook payload: apply status updates and record
     * inbound messages. Unknown shapes are ignored.
     *
     * @param  array<string,mixed>  $payload
     */
    public function process(array $payload): void
    {
        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);
                foreach (data_get($value, 'statuses', []) as $status) {
                    $this->applyStatus(is_array($status) ? $status : []);
                }
                foreach (data_get($value, 'messages', []) as $message) {
                    $this->recordInbound(is_array($message) ? $message : []);
                }
            }
        }
    }

    /** @param array<string,mixed> $status */
    private function applyStatus(array $status): void
    {
        $id = $status['id'] ?? null;
        $state = $status['status'] ?? null;
        if (! $id || ! is_string($state)) {
            return;
        }

        $message = WhatsAppMessage::where('provider_message_id', $id)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)->first();
        if (! $message) {
            return;
        }

        $at = isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now();
        // Rank prevents a late/out-of-order event from regressing the state.
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
        $current = $rank[$message->status->value] ?? 0;

        match ($state) {
            'sent' => $current < 1 ? $message->update([
                'status' => WhatsAppMessageStatus::Sent,
                'sent_at' => $message->sent_at ?? $at,
            ]) : null,
            'delivered' => $current < 2 ? $message->update([
                'status' => WhatsAppMessageStatus::Delivered,
                'delivered_at' => $at,
            ]) : null,
            'read' => $current < 3 ? $message->update([
                'status' => WhatsAppMessageStatus::Read,
                'read_at' => $at,
            ]) : null,
            'failed' => $message->update([
                'status' => WhatsAppMessageStatus::Failed,
                'failed_at' => $at,
                'failure_reason' => (string) (data_get($status, 'errors.0.title')
                    ?: data_get($status, 'errors.0.message') ?: 'فشل الإرسال من واتساب'),
            ]),
            default => null,
        };
    }

    /** @param array<string,mixed> $message */
    private function recordInbound(array $message): void
    {
        $wamid = $message['id'] ?? null;
        $from = (string) ($message['from'] ?? '');
        if ($from === '') {
            return;
        }
        // Idempotent: Meta retries deliver the same message id.
        if ($wamid && WhatsAppMessage::where('provider_message_id', $wamid)->exists()) {
            return;
        }

        $body = data_get($message, 'text.body') ?: '['.(string) ($message['type'] ?? 'رسالة').']';
        $tail = substr(preg_replace('/\D+/', '', $from) ?? '', -9);
        $customer = $tail !== '' ? Customer::where('whatsapp_number', 'like', "%{$tail}")
            ->orWhere('phone', 'like', "%{$tail}")->first() : null;

        WhatsAppMessage::create([
            'customer_id' => $customer?->id,
            'phone' => $from,
            'message_body' => (string) $body,
            'provider' => 'meta_cloud',
            'provider_message_id' => $wamid,
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'status' => WhatsAppMessageStatus::Delivered,
            'delivered_at' => now(),
        ]);
    }
}
