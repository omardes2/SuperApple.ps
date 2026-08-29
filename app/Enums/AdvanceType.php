<?php

namespace App\Enums;

enum AdvanceType: string
{
    case Advance = 'advance';
    case Loan = 'loan';

    public function label(): string
    {
        return $this === self::Advance ? 'سلفة' : 'قرض';
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
