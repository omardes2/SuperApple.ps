<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Active => 'نشط',
            self::Paused => 'موقوف مؤقتاً',
            self::Cancelled => 'ملغى',
            self::Expired => 'منتهٍ',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600',
            self::Active => 'bg-emerald-50 text-emerald-700',
            self::Paused => 'bg-amber-50 text-amber-700',
            self::Cancelled => 'bg-red-50 text-red-700',
            self::Expired => 'bg-slate-200 text-slate-500',
        };
    }

    /** Only an active subscription is ever eligible for automatic billing. */
    public function isBillable(): bool
    {
        return $this === self::Active;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
