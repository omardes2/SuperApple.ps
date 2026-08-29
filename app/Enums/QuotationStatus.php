<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Sent => 'مُرسل',
            self::Accepted => 'مقبول',
            self::Rejected => 'مرفوض',
            self::Expired => 'منتهي الصلاحية',
            self::Cancelled => 'ملغى',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600',
            self::Sent => 'bg-brand-50 text-brand-700',
            self::Accepted => 'bg-emerald-50 text-emerald-700',
            self::Rejected => 'bg-red-50 text-red-700',
            self::Expired => 'bg-amber-50 text-amber-700',
            self::Cancelled => 'bg-slate-200 text-slate-500',
        };
    }

    /** Items and financial fields are frozen once the document leaves Draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
