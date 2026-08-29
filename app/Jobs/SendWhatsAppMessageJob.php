<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\WhatsAppSendFailed;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Delivers one WhatsApp message. Dispatched only after the financial
 * transaction it belongs to has committed, so its success or failure never
 * affects invoices or payments. Retries a bounded number of times with
 * backoff; a final failure marks the message Failed and notifies staff — it is
 * never retried infinitely.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

    /** Backoff between retries, in seconds. */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(WhatsAppService $service): void
    {
        $message = WhatsAppMessage::find($this->messageId);
        if (! $message || $message->status->isTerminal()) {
            return;
        }

        try {
            $service->deliver($message);
        } catch (Throwable $e) {
            $isLastAttempt = $this->attempts() >= $this->tries;

            if ($isLastAttempt || $this->connectionIsSync()) {
                $service->markFailed($message, $e->getMessage());
                $this->notifyStaff($message, $e->getMessage());

                return; // never rethrow — the caller must stay unaffected
            }

            $service->markQueuedForRetry($message, $e->getMessage());
            $this->release($this->backoff()[$this->attempts() - 1] ?? 300);
        }
    }

    private function connectionIsSync(): bool
    {
        $connection = $this->job?->getConnectionName() ?? config('queue.default');

        return $connection === 'sync';
    }

    private function notifyStaff(WhatsAppMessage $message, string $reason): void
    {
        try {
            $recipients = User::permission('whatsapp.view')->get();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new WhatsAppSendFailed($message, $reason));
            }
        } catch (Throwable $e) {
            // Notification is best-effort; swallow.
        }
    }
}
