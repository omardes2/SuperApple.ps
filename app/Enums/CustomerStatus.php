<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Lead = 'lead';
    case Active = 'active';
    case Inactive = 'inactive';
    case OnHold = 'on_hold';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'عميل محتمل',
            self::Active => 'نشط',
            self::Inactive => 'غير نشط',
            self::OnHold => 'معلّق',
            self::Archived => 'مؤرشف',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Lead => 'bg-violet-50 text-violet-700',
            self::Active => 'bg-emerald-50 text-emerald-700',
            self::Inactive => 'bg-slate-100 text-slate-600',
            self::OnHold => 'bg-amber-50 text-amber-700',
            self::Archived => 'bg-slate-200 text-slate-500',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
