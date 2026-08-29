<?php

namespace App\Support\WhatsApp;

use App\Enums\WhatsAppMessageStatus;

/**
 * The normalised outcome of a provider send attempt. Providers never touch the
 * database — they return one of these and the WhatsAppService records it.
 */
final class WhatsAppSendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly WhatsAppMessageStatus $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(true, WhatsAppMessageStatus::Sent, $providerMessageId, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, WhatsAppMessageStatus::Failed, null, $error);
    }
}
