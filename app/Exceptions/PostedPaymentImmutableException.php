<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to mutate a financial field of a payment that has
 * already been posted. Posted payments are historical records; corrections go
 * through Cancel + reversal, never by editing.
 */
class PostedPaymentImmutableException extends RuntimeException
{
    public static function forField(string $field): self
    {
        return new self("لا يمكن تعديل الحقل [{$field}] بعد ترحيل الدفعة. يجب إلغاء الدفعة وإنشاء دفعة جديدة.");
    }
}
