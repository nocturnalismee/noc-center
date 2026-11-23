<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A value that is a real JSON number (int or float).
 *
 * Laravel's `numeric` rule accepts numeric strings (`"12.5"`), which are
 * valid JSON strings, not numbers. `IngestPayload::metric()` keeps only
 * `int|float` and would silently store `0` for such a value, so the payload
 * is refused at validation instead. Booleans and numeric strings fail here.
 */
class JsonNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! is_float($value)) {
            $fail('The :attribute field must be a number.');
        }
    }
}
