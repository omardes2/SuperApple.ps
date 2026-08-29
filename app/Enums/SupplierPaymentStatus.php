<?php

namespace App\Enums;

enum SupplierPaymentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Posted => 'مُرحّلة',
            self::Cancelled => 'ملغاة',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600',
            self::Posted => 'bg-emerald-50 text-emerald-700',
            self::Cancelled => 'bg-red-50 text-red-700',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
