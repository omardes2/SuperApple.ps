<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a WhatsApp template references a variable that was not supplied.
 * The message is never sent half-rendered — the render is rejected and logged.
 */
class MissingTemplateVariableException extends RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('متغيرات ناقصة في القالب: '.implode('، ', $missing));
    }
}
