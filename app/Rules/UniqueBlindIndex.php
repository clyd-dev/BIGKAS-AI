<?php

namespace App\Rules;

use App\Support\BlindIndex;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * "Unique" rule for a value stored encrypted: checks the blind index instead of the column.
 *   new UniqueBlindIndex('users', 'email_index', 'email', $ignoreId)
 */
class UniqueBlindIndex implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $indexColumn,
        private string $context,
        private ?int $ignoreId = null,
        private string $message = 'This value is already in use.',
    ) {}

    public static function email(?int $ignoreId = null): self
    {
        return new self('users', 'email_index', 'email', $ignoreId, 'The email has already been taken.');
    }

    public static function lrn(?int $ignoreId = null): self
    {
        return new self('learners', 'lrn_index', 'lrn', $ignoreId, 'This LRN is already registered.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $index = BlindIndex::make((string) $value, $this->context);
        if ($index === null) {
            return;
        }

        $exists = DB::table($this->table)
            ->where($this->indexColumn, $index)
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail($this->message);
        }
    }
}
