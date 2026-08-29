<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProvider;
use App\Support\WhatsApp\WhatsAppSendResult;
use RuntimeException;

/**
 * An in-memory provider for tests and local demos. It never touches the network:
 * it records every send and can be told to fail, so WhatsApp behaviour is fully
 * testable offline. Register it as a singleton so assertions see the same state.
 */
class FakeWhatsAppProvider implements WhatsAppProvider
{
    /** @var list<array{phone:string,body:string}> */
    public array $sent = [];

    private bool $shouldFail = false;

    private string $failMessage = 'Fake provider forced failure';

    public function key(): string
    {
        return 'fake';
    }

    /** Force the next sends to fail (simulates a provider/network error). */
    public function fail(string $message = 'Fake provider forced failure'): void
    {
        $this->shouldFail = true;
        $this->failMessage = $message;
    }

    public function succeed(): void
    {
        $this->shouldFail = false;
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->shouldFail = false;
    }

    public function sendText(string $phone, string $body): WhatsAppSendResult
    {
        if ($this->shouldFail) {
            // Throwing exercises the job's retry/failure handling; the service
            // catches provider errors so financial state is never affected.
            throw new RuntimeException($this->failMessage);
        }

        $this->sent[] = ['phone' => $phone, 'body' => $body];

        return WhatsAppSendResult::sent('fake-'.count($this->sent));
    }

    public function sendTemplate(string $phone, string $templateKey, array $variables, string $renderedBody): WhatsAppSendResult
    {
        return $this->sendText($phone, $renderedBody);
    }

    public function getMessageStatus(string $providerMessageId): ?string
    {
        return 'delivered';
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function lastBody(): ?string
    {
        $last = end($this->sent);

        return $last === false ? null : $last['body'];
    }
}
