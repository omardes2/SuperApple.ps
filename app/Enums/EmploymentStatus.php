<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Resigned = 'resigned';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Suspended => 'موقوف',
            self::Resigned => 'مستقيل',
            self::Terminated => 'منتهي الخدمة',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-700',
            self::Suspended => 'bg-amber-50 text-amber-700',
            self::Resigned => 'bg-slate-100 text-slate-600',
            self::Terminated => 'bg-red-50 text-red-700',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
