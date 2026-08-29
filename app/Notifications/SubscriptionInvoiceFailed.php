<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A subscription's recurring invoice generation failed with an error. */
class SubscriptionInvoiceFailed extends Notification
{
    use Queueable;

    public function __construct(public Subscription $subscription, public string $error) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription.invoice_failed',
            'title' => 'فشل توليد فاتورة اشتراك',
            'message' => "تعذّر توليد فاتورة للاشتراك {$this->subscription->subscription_number}: {$this->error}",
            'subscription_id' => $this->subscription->id,
        ];
    }
}
