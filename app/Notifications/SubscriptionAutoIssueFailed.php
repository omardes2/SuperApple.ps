<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A recurring invoice draft was created but could not be auto-issued because no
 * exchange rate exists for its date. The accountant must set a rate and issue
 * it manually — the system never guesses a rate.
 */
class SubscriptionAutoIssueFailed extends Notification
{
    use Queueable;

    public function __construct(public Subscription $subscription, public Invoice $invoice) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription.auto_issue_failed',
            'title' => 'تعذّر إصدار فاتورة اشتراك تلقائياً',
            'message' => "تم إنشاء مسودة فاتورة للاشتراك {$this->subscription->subscription_number} لكن لا يوجد سعر صرف لإصدارها. يرجى إدخال سعر الصرف وإصدارها يدوياً.",
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
        ];
    }
}
