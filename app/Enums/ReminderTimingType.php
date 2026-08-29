<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * When a payment reminder fires relative to an invoice due date.
 *   BeforeDue  — offset_days before the due date (e.g. 3 days before).
 *   DueDate    — on the due date itself.
 *   AfterDue   — offset_days after the due date (overdue reminder).
 */
enum ReminderTimingType: string
{
    case BeforeDue = 'before_due';
    case DueDate = 'due_date';
    case AfterDue = 'after_due';

    public function label(): string
    {
        return match ($this) {
            self::BeforeDue => 'قبل الاستحقاق',
            self::DueDate => 'يوم الاستحقاق',
            self::AfterDue => 'بعد الاستحقاق',
        };
    }

    /**
     * The target send date for a given invoice due date. BeforeDue subtracts the
     * offset, AfterDue adds it, DueDate ignores the offset entirely.
     */
    public function sendDateFor(Carbon $dueDate, int $offsetDays): Carbon
    {
        return match ($this) {
            self::BeforeDue => $dueDate->copy()->subDays(max(0, $offsetDays)),
            self::DueDate => $dueDate->copy(),
            self::AfterDue => $dueDate->copy()->addDays(max(0, $offsetDays)),
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
