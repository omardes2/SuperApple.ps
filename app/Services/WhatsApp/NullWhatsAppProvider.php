<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProvider;
use App\Support\WhatsApp\WhatsAppSendResult;

/**
 * The safe default: pretends to send but does nothing and reaches no network.
 * Used whenever WhatsApp is disabled or no real provider is configured.
 */
class NullWhatsAppProvider implements WhatsAppProvider
{
    public function key(): string
    {
        return 'null';
    }

    public function sendText(string $phone, string $body): WhatsAppSendResult
    {
        return WhatsAppSendResult::sent('null-'.substr(md5($phone.$body.microtime()), 0, 16));
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
