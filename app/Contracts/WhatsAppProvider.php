<?php

namespace App\Contracts;

use App\Support\WhatsApp\WhatsAppSendResult;

/**
 * The provider abstraction for outbound WhatsApp. Concrete drivers (Null, Log,
 * Fake, and — later — Meta Cloud API / 360dialog) implement this. Credentials
 * live in Settings/ENV only and are read by the driver, never hard-coded.
 *
 * Message bodies reach the provider fully rendered; template variable
 * substitution and validation happen upstream in TemplateRenderer.
 */
interface WhatsAppProvider
{
    /** A stable identifier stored on each message row (e.g. "fake", "log"). */
    public function key(): string;

    /** Send a plain text message to a normalised phone number. */
    public function sendText(string $phone, string $body): WhatsAppSendResult;

    /**
     * Send using a provider-side template. The default flows render locally and
     * call sendText; this exists so a real API driver can use native templates.
     *
     * @param  array<string,string>  $variables
     */
    public function sendTemplate(string $phone, string $templateKey, array $variables, string $renderedBody): WhatsAppSendResult;

    /** Poll delivery status for a previously sent message, or null if unknown. */
    public function getMessageStatus(string $providerMessageId): ?string;
}
