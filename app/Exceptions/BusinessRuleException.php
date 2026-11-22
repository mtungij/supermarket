<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A request that is well-formed but violates a business rule. Extends
 * ValidationException so forms and Livewire components show the message
 * next to the relevant field.
 */
class BusinessRuleException extends ValidationException
{
    public static function make(string $field, string $message): static
    {
        return static::withMessages([$field => $message]);
    }
}
