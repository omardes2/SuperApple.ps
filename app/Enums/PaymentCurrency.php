<?php

namespace App\Enums;

enum PaymentCurrency: string
{
    case USD = 'USD';
    case ILS = 'ILS';

    public function label(): string
    {
        return match ($this) {
            self::USD => 'دولار (USD)',
            self::ILS => 'شيكل (ILS)',
        };
    }

    public function symbol(): string
    {
        return $this === self::USD ? '$' : '₪';
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
