<?php

namespace App\Support;

use App\Rules\StrongPassword;

/**
 * The one definition of an acceptable password. The server rules, the error messages and the
 * checklist shown on the register page all read from here, so they can never disagree.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** Checklist items: id, label shown to the user, and the pattern the page uses to tick it off live. */
    public static function requirements(): array
    {
        return [
            ['id' => 'length', 'label' => 'At least ' . self::MIN_LENGTH . ' characters', 'pattern' => '.{' . self::MIN_LENGTH . ',}'],
            ['id' => 'lower',  'label' => 'One lowercase letter (a–z)',                    'pattern' => '[a-z]'],
            ['id' => 'upper',  'label' => 'One uppercase letter (A–Z)',                    'pattern' => '[A-Z]'],
            ['id' => 'digit',  'label' => 'One number (0–9)',                              'pattern' => '[0-9]'],
        ];
    }

    /** What is missing from a password, in plain words (empty when it is acceptable). */
    public static function missing(string $password): array
    {
        $missing = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $missing[] = 'at least ' . self::MIN_LENGTH . ' characters';
        }
        if (! preg_match('/[a-z]/', $password)) {
            $missing[] = 'a lowercase letter';
        }
        if (! preg_match('/[A-Z]/', $password)) {
            $missing[] = 'an uppercase letter';
        }
        if (! preg_match('/[0-9]/', $password)) {
            $missing[] = 'a number';
        }

        return $missing;
    }

    /** Validation rules for a new password (with a matching password_confirmation field). */
    public static function rules(): array
    {
        return ['required', 'string', 'confirmed', new StrongPassword()];
    }

    public static function messages(): array
    {
        return [
            'password.required'  => 'Please enter a password.',
            'password.confirmed' => 'The two password boxes do not match.',
        ];
    }
}
