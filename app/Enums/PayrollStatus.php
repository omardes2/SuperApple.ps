<?php

namespace App\Enums;

enum PayrollStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Posted = 'posted';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Calculated => 'محتسبة',
            self::Approved => 'معتمدة',
            self::Posted => 'مُرحّلة',
            self::Paid => 'مدفوعة',
            self::Cancelled => 'ملغاة',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600',
            self::Calculated => 'bg-blue-50 text-blue-700',
            self::Approved => 'bg-violet-50 text-violet-700',
            self::Posted => 'bg-emerald-50 text-emerald-700',
            self::Paid => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-red-50 text-red-700',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Calculated], true);
    }

    public function isPosted(): bool
    {
        return in_array($this, [self::Posted, self::Paid], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
