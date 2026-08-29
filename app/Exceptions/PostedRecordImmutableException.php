<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a locked field on a posted/reversed financial record (journal
 * entry, expense, supplier bill/payment) is mutated. Corrections are made by
 * reversal + a new record, never by editing history.
 */
class PostedRecordImmutableException extends RuntimeException
{
    public static function forField(string $field, string $record = 'السجل'): self
    {
        return new self("لا يمكن تعديل الحقل [{$field}] بعد ترحيل {$record}. أنشئ قيد عكس ثم سجلاً جديداً.");
    }
}
