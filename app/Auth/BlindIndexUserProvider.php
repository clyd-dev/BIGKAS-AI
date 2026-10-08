<?php

namespace App\Auth;

use App\Support\BlindIndex;
use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * Eloquent user provider that finds a user by the blind index of the (encrypted) email.
 * Used by login, password reset and everything else that looks a user up by credentials.
 */
class BlindIndexUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $credentials = array_filter($credentials, fn ($key) => ! Str::contains($key, 'password'), ARRAY_FILTER_USE_KEY);

        if (empty($credentials)) {
            return null;
        }

        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if ($key === 'email') {
                $query->where('email_index', BlindIndex::make((string) $value, 'email') ?? '-none-');
            } elseif (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } elseif ($value instanceof Closure) {
                $value($query);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }
}
