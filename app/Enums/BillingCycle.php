<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * The billing cadence of a subscription. Combined with a positive
 * `billing_interval` it expresses "every N cycles" (e.g. Monthly + 2 =
 * every two months). No proration is applied in Sprint 7: the first period
 * is always a full cycle counted from the subscription start date.
 */
enum BillingCycle: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Yearly = 'yearly';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'أسبوعي',
            self::Monthly => 'شهري',
            self::Quarterly => 'ربع سنوي',
            self::SemiAnnual => 'نصف سنوي',
            self::Yearly => 'سنوي',
            self::Custom => 'مخصص',
        };
    }

    /**
     * Advance a date by one billing period (interval cycles). Weekly moves in
     * weeks; every other cycle moves in whole months so month-ends stay aligned.
     */
    public function advance(Carbon $date, int $interval = 1): Carbon
    {
        $interval = max(1, $interval);
        $next = $date->copy();

        return match ($this) {
            self::Weekly => $next->addWeeks($interval),
            self::Monthly => $next->addMonthsNoOverflow($interval),
            self::Quarterly => $next->addMonthsNoOverflow(3 * $interval),
            self::SemiAnnual => $next->addMonthsNoOverflow(6 * $interval),
            self::Yearly => $next->addMonthsNoOverflow(12 * $interval),
            self::Custom => $next->addMonthsNoOverflow($interval),
        };
    }

    /**
     * Number of months a single billing period spans (interval included). Used
     * to normalise the subscription value to a monthly figure (MRR). Weekly is
     * approximated using an average month length of 30.4375 days.
     */
    public function monthsPerPeriod(int $interval = 1): float
    {
        $interval = max(1, $interval);

        return match ($this) {
            self::Weekly => $interval * 7 / 30.4375,
            self::Monthly => (float) $interval,
            self::Quarterly => 3.0 * $interval,
            self::SemiAnnual => 6.0 * $interval,
            self::Yearly => 12.0 * $interval,
            self::Custom => (float) $interval,
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
