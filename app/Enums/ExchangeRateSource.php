<?php

namespace App\Enums;

enum ExchangeRateSource: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Bank = 'bank';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'يدوي',
            self::Api => 'API',
            self::Bank => 'بنك',
            self::Other => 'أخرى',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
