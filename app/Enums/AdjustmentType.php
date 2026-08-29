<?php

namespace App\Enums;

enum AdjustmentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';

    public function label(): string
    {
        return $this === self::Earning ? 'استحقاق' : 'استقطاع';
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
