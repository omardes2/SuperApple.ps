<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case OnHold = 'on_hold';
    case UnderReview = 'under_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Active => 'نشط',
            self::OnHold => 'معلّق',
            self::UnderReview => 'قيد المراجعة',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغى',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600',
            self::Active => 'bg-emerald-50 text-emerald-700',
            self::OnHold => 'bg-amber-50 text-amber-700',
            self::UnderReview => 'bg-brand-50 text-brand-700',
            self::Completed => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-red-50 text-red-700',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
