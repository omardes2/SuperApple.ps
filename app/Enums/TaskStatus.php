<?php

namespace App\Enums;

enum TaskStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case WaitingReview = 'waiting_review';
    case ChangesRequested = 'changes_requested';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديدة',
            self::InProgress => 'قيد التنفيذ',
            self::WaitingReview => 'بانتظار المراجعة',
            self::ChangesRequested => 'مطلوب تعديلات',
            self::Completed => 'مكتملة',
            self::Cancelled => 'ملغاة',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::New => 'bg-slate-100 text-slate-600',
            self::InProgress => 'bg-brand-50 text-brand-700',
            self::WaitingReview => 'bg-amber-50 text-amber-700',
            self::ChangesRequested => 'bg-red-50 text-red-700',
            self::Completed => 'bg-emerald-50 text-emerald-700',
            self::Cancelled => 'bg-slate-200 text-slate-500',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
