<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A subscription's end date is approaching. */
class SubscriptionExpiringSoon extends Notification
{
    use Queueable;

    public function __construct(public Subscription $subscription, public int $daysLeft) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription.expiring_soon',
            'title' => 'اشتراك يقترب من الانتهاء',
            'message' => "الاشتراك {$this->subscription->subscription_number} سينتهي خلال {$this->daysLeft} يوم.",
            'subscription_id' => $this->subscription->id,
        ];
    }
}
