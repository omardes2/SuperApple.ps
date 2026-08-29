<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Management metrics for subscriptions. MRR/ARR are *contracted* recurring value
 * — a planning figure, NOT accounting revenue. Revenue is only recognised when
 * an actual invoice is issued (through InvoiceService).
 *
 * MRR normalises each active subscription's total to one month:
 *   Monthly = total ; Quarterly = total / 3 ; Yearly = total / 12
 * (and pro-rata for other cycles/intervals). ARR = MRR × 12.
 */
class SubscriptionMetricsService
{
    /** Monthly Recurring Revenue (USD) across active subscriptions. */
    public function mrr(): string
    {
        $total = '0.00';
        foreach (Subscription::active()->get() as $sub) {
            $total = Money::add($total, $sub->monthlyRecurringRevenue());
        }

        return Money::money($total);
    }

    /** Annual Recurring Revenue = MRR × 12 (USD). */
    public function arr(): string
    {
        return Money::multiply($this->mrr(), 12);
    }

    /** @return array<string,int> */
    public function countsByStatus(): array
    {
        $counts = [];
        foreach (SubscriptionStatus::cases() as $status) {
            $counts[$status->value] = Subscription::where('status', $status->value)->count();
        }

        return $counts;
    }

    /** Active subscriptions due to bill within the next $days days. */
    public function upcomingBillings(int $days = 30)
    {
        $until = Carbon::now()->addDays($days)->toDateString();

        return Subscription::active()
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<=', $until)
            ->orderBy('next_billing_date')
            ->get();
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return [
            'mrr_usd' => $this->mrr(),
            'arr_usd' => $this->arr(),
            'active' => Subscription::active()->count(),
            'counts' => $this->countsByStatus(),
        ];
    }
}
