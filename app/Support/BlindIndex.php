<?php

namespace App\Support;

/**
 * Keyed fingerprint of a value, used to look a row up by an encrypted column (login by email, LRN
 * de-duplication) without storing the value readable. It supports exact matches only.
 *
 * HMAC-SHA256 with a key derived from APP_KEY, so the fingerprint cannot be rebuilt without the key.
 */
class BlindIndex
{
    public static function make(?string $value, string $context): ?string
    {
        $normalized = self::normalize($value, $context);
        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, self::key($context));
    }

    public static function normalize(?string $value, string $context): string
    {
        $value = trim((string) $value);

        return match ($context) {
            'email' => mb_strtolower($value),
            'lrn'   => preg_replace('/\D+/', '', $value) ?? '',
            default => mb_strtolower($value),
        };
    }

    private static function key(string $context): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7)) ?: $appKey;
        }

        return hash_hkdf('sha256', $appKey, 32, 'bigkas-blind-index:' . $context);
    }
}
