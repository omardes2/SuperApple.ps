<?php

namespace App\Enums;

enum ServiceType: string
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'مرة واحدة',
            self::Monthly => 'شهري',
            self::Yearly => 'سنوي',
            self::Custom => 'مخصص',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
