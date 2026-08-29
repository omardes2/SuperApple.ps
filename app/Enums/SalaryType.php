<?php

namespace App\Enums;

enum SalaryType: string
{
    case Monthly = 'monthly';
    case Daily = 'daily';
    case Hourly = 'hourly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'شهري',
            self::Daily => 'يومي',
            self::Hourly => 'بالساعة',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
