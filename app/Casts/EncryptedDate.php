<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/** A date stored encrypted ("Y-m-d" text, encrypted) and read back as a Carbon date, like the 'date' cast. */
class EncryptedDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse(Crypt::decryptString($value))->startOfDay();
        } catch (DecryptException) {
            // Not encrypted yet (row from before the encryption migration): read it as a plain date.
            return Carbon::parse($value)->startOfDay();
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString(Carbon::parse($value)->toDateString());
    }
}
