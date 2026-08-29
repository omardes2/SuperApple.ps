<?php

namespace App\Enums;

enum Currency: string
{
    case ILS = 'ILS';
    case USD = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::ILS => 'شيكل (ILS)',
            self::USD => 'دولار (USD)',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::ILS => '₪',
            self::USD => '$',
        };
    }
}
