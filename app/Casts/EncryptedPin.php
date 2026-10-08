<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Student-portal PIN: stored encrypted (reversible with APP_KEY) so the teacher can read it,
 * never as plain text in the database.
 *
 * PINs saved before this change are bcrypt hashes ("$2y$..."). They cannot be read back, so they come out as
 * null here; Learner::checkPin() still accepts them until the PIN is re-issued or the child next logs in.
 */
class EncryptedPin implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '' || self::isLegacyHash($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    public static function isLegacyHash(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, '$2y$');
    }
}
