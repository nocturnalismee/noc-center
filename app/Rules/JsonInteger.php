<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A value that is a real JSON integer.
 *
 * Laravel's `integer` rule accepts `true` and `1.0` (both loosely equal to
 * an integer), which is wrong for a JSON protocol: the agent must send the
 * literal `1`, not `true`, `1.0` or `"1"`. A strict PHP type check is exact:
 * only decoded JSON numbers without a fraction are `int`.
 */
class JsonInteger implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail('The :attribute field must be an integer.');
        }
    }
}
