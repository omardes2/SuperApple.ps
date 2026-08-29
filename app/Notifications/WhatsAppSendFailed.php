<?php

namespace App\Notifications;

use App\Models\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A WhatsApp message exhausted its retries and was marked Failed. */
class WhatsAppSendFailed extends Notification
{
    use Queueable;

    public function __construct(public WhatsAppMessage $message, public string $reason) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'whatsapp.send_failed',
            'title' => 'فشل إرسال رسالة واتساب',
            'message' => "تعذّر إرسال رسالة واتساب إلى {$this->message->phone}: {$this->reason}",
            'whatsapp_message_id' => $this->message->id,
            'customer_id' => $this->message->customer_id,
        ];
    }
}
