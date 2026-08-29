<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts to mutate a financial field of an invoice that
 * has already been issued. Issued invoices are historical records; corrections
 * happen through Cancel + re-issue, never by editing.
 */
class IssuedInvoiceImmutableException extends RuntimeException
{
    public static function forField(string $field): self
    {
        return new self("لا يمكن تعديل الحقل [{$field}] بعد إصدار الفاتورة. يجب إلغاء الفاتورة وإصدار فاتورة جديدة.");
    }

    public static function forItems(): self
    {
        return new self('لا يمكن تعديل بنود فاتورة صادرة.');
    }
}
