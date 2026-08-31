<?php

namespace App\Enums;

/**
 * The independent execution state of a single task member (primary assignee or
 * participant). Each member owns their own state; the task is only Completed
 * once every active member reaches Completed.
 */
enum TaskMemberStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'لم يبدأ',
            self::InProgress => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NotStarted => 'bg-slate-100 text-slate-600',
            self::InProgress => 'bg-brand-50 text-brand-700',
            self::Completed => 'bg-emerald-50 text-emerald-700',
        };
    }

    /** A small status dot colour for the team card. */
    public function dotClass(): string
    {
        return match ($this) {
            self::NotStarted => 'bg-slate-300',
            self::InProgress => 'bg-brand-500',
            self::Completed => 'bg-emerald-500',
        };
    }
}
