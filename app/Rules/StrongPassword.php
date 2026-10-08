<?php

namespace App\Rules;

use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Fails with a message that names exactly what the password is missing (no guessing). */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $missing = PasswordPolicy::missing((string) $value);

        if ($missing) {
            $fail('Your password needs ' . self::list($missing) . '.');
        }
    }

    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items ? implode(', ', $items) . ' and ' . $last : $last;
    }
}
