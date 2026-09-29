<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class EmailVerificationCode extends Model
{
    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 30;

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh code for the user, voiding any previous one.
     * Returns the PLAINTEXT code — the caller sends it, never stores it.
     */
    public static function issueFor(User $user): string
    {
        static::where('user_id', $user->id)->delete();

        $code = (string) random_int(100000, 999999);

        static::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'attempts' => 0,
        ]);

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    public function hasMaxAttempts(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
