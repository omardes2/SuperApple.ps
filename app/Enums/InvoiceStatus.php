<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Sent = 'sent';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Issued => 'صادرة',
            self::Sent => 'مُرسلة',
            self::PartiallyPaid => 'مدفوعة جزئياً',
            self::Paid => 'مدفوعة',
            self::Overdue => 'متأخرة',
            self::Cancelled => 'ملغاة',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600',
            self::Issued => 'bg-brand-50 text-brand-700',
            self::Sent => 'bg-violet-50 text-violet-700',
            self::PartiallyPaid => 'bg-amber-50 text-amber-700',
            self::Paid => 'bg-emerald-50 text-emerald-700',
            self::Overdue => 'bg-red-50 text-red-700',
            self::Cancelled => 'bg-slate-200 text-slate-500',
        };
    }

    /** A Draft is the only state whose financial fields may be edited. */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    /** Has the invoice been issued (locked) and not cancelled? */
    public function isIssuedAndActive(): bool
    {
        return ! in_array($this, [self::Draft, self::Cancelled], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
