<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProvider;
use App\Support\WhatsApp\WhatsAppSendResult;
use Illuminate\Support\Facades\Log;

/**
 * Writes the outbound message to the application log instead of a real network
 * call. Useful in staging to eyeball rendered content without sending anything.
 */
class LogWhatsAppProvider implements WhatsAppProvider
{
    public function key(): string
    {
        return 'log';
    }

    public function sendText(string $phone, string $body): WhatsAppSendResult
    {
        Log::channel(config('logging.default'))->info('WhatsApp (log driver) message', [
            'phone' => $phone,
            'body' => $body,
        ]);

        return WhatsAppSendResult::sent('log-'.substr(md5($phone.$body.microtime()), 0, 16));
    }

    public function sendTemplate(string $phone, string $templateKey, array $variables, string $renderedBody): WhatsAppSendResult
    {
        return $this->sendText($phone, $renderedBody);
    }

    public function getMessageStatus(string $providerMessageId): ?string
    {
        return null;
    }
}
