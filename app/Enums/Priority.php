<?php

namespace App\Enums;

/**
 * Shared priority scale for both projects and tasks.
 */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'منخفضة',
            self::Normal => 'عادية',
            self::High => 'عالية',
            self::Urgent => 'عاجلة',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Low => 'bg-slate-100 text-slate-600',
            self::Normal => 'bg-brand-50 text-brand-700',
            self::High => 'bg-amber-50 text-amber-700',
            self::Urgent => 'bg-red-50 text-red-700',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
