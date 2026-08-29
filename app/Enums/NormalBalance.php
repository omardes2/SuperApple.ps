<?php

namespace App\Enums;

enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return $this === self::Debit ? 'مدين' : 'دائن';
    }
}
