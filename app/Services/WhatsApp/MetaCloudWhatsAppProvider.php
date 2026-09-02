<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProvider;
use App\Services\Settings;
use App\Support\WhatsApp\WhatsAppSendResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The official Meta WhatsApp Cloud API driver (graph.facebook.com). Credentials
 * are read from Settings (whatsapp.*), with an env/config fallback — never
 * hard-coded. Bodies arrive fully rendered from TemplateRenderer upstream.
 *
 * A failed send returns a failed result (with the API's error message) rather
 * than throwing, so the WhatsAppService records the failure without leaking
 * secrets; genuine transport errors bubble to the job's retry handling.
 */
class MetaCloudWhatsAppProvider implements WhatsAppProvider
{
    public function __construct(private readonly Settings $settings) {}

    public function key(): string
    {
        return 'meta_cloud';
    }

    public function sendText(string $phone, string $body): WhatsAppSendResult
    {
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalise($phone),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendTemplate(string $phone, string $templateKey, array $variables, string $renderedBody): WhatsAppSendResult
    {
        // Business-initiated conversations outside the 24h window require an
        // approved provider-side template. We send the named template with the
        // ordered variable values as body parameters; language defaults to the
        // configured template locale. When no template is configured we fall
        // back to the locally rendered text (valid inside the 24h window).
        $language = (string) ($this->setting('meta_template_language') ?: 'ar');

        $parameters = array_map(
            fn ($value) => ['type' => 'text', 'text' => (string) $value],
            array_values($variables),
        );

        $components = $parameters === []
            ? []
            : [['type' => 'body', 'parameters' => $parameters]];

        if ($templateKey === '') {
            return $this->sendText($phone, $renderedBody);
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalise($phone),
            'type' => 'template',
            'template' => [
                'name' => $templateKey,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    public function sendDocument(string $phone, string $body, string $documentPath, string $filename): WhatsAppSendResult
    {
        if (! is_file($documentPath)) {
            return WhatsAppSendResult::failed('ملف المستند غير موجود.');
        }

        $creds = $this->credentials();
        if ($creds === null) {
            return WhatsAppSendResult::failed('لم تُضبط بيانات اعتماد واتساب (Meta Cloud API).');
        }

        // Step 1 — upload the media and obtain a media id.
        $upload = $this->client($creds['token'])
            ->attach('file', file_get_contents($documentPath), $filename)
            ->post("{$creds['base']}/{$creds['phone_id']}/media", [
                'messaging_product' => 'whatsapp',
            ]);

        if ($upload->failed() || blank($upload->json('id'))) {
            return WhatsAppSendResult::failed($this->errorMessage($upload, 'فشل رفع المستند إلى واتساب.'));
        }

        // Step 2 — send the document message referencing the uploaded media id.
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalise($phone),
            'type' => 'document',
            'document' => array_filter([
                'id' => (string) $upload->json('id'),
                'caption' => $body !== '' ? $body : null,
                'filename' => $filename,
            ]),
        ]);
    }

    public function getMessageStatus(string $providerMessageId): ?string
    {
        // The Cloud API reports delivery status through webhooks, not a poll
        // endpoint, so status is unknown here.
        return null;
    }

    /**
     * POST a message payload to the Cloud API and normalise the outcome.
     *
     * @param  array<string,mixed>  $payload
     */
    private function postMessage(array $payload): WhatsAppSendResult
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return WhatsAppSendResult::failed('لم تُضبط بيانات اعتماد واتساب (Meta Cloud API).');
        }

        $response = $this->client($creds['token'])
            ->post("{$creds['base']}/{$creds['phone_id']}/messages", $payload);

        if ($response->failed()) {
            return WhatsAppSendResult::failed($this->errorMessage($response, 'تعذّر إرسال رسالة واتساب.'));
        }

        $id = $response->json('messages.0.id');

        return $id
            ? WhatsAppSendResult::sent((string) $id)
            : WhatsAppSendResult::failed('استجابة واتساب لا تحتوي معرّف رسالة.');
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->timeout(20)->acceptJson();
    }

    /**
     * Resolve credentials + base URL, or null when not fully configured.
     *
     * @return array{token:string,phone_id:string,base:string}|null
     */
    private function credentials(): ?array
    {
        $token = (string) ($this->setting('meta_access_token') ?: '');
        $phoneId = (string) ($this->setting('meta_phone_number_id') ?: '');
        if ($token === '' || $phoneId === '') {
            return null;
        }

        $version = (string) ($this->setting('meta_api_version') ?: 'v21.0');

        return [
            'token' => $token,
            'phone_id' => $phoneId,
            'base' => 'https://graph.facebook.com/'.$version,
        ];
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('whatsapp', $key) ?: config("services.whatsapp.{$key}");
    }

    /** A digits-only recipient (Cloud API expects no leading +). */
    private function normalise(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        return (string) ($response->json('error.message') ?: $fallback);
    }
}
