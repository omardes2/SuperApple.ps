<?php

namespace App\Enums;

enum AdvanceStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
    case PartiallyRecovered = 'partially_recovered';
    case Recovered = 'recovered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Approved => 'معتمدة',
            self::Paid => 'مدفوعة',
            self::PartiallyRecovered => 'مستردة جزئياً',
            self::Recovered => 'مستردة بالكامل',
            self::Cancelled => 'ملغاة',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600',
            self::Approved => 'bg-blue-50 text-blue-700',
            self::Paid => 'bg-amber-50 text-amber-700',
            self::PartiallyRecovered => 'bg-amber-50 text-amber-700',
            self::Recovered => 'bg-emerald-50 text-emerald-700',
            self::Cancelled => 'bg-red-50 text-red-700',
        };
    }

    /** Advance is outstanding and recoverable from payroll. */
    public function isRecoverable(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRecovered], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
