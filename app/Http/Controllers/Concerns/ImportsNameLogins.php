<?php

namespace App\Http\Controllers\Concerns;

use App\Support\BlindIndex;
use Illuminate\Support\Facades\DB;

/**
 * Shared by the parent and teacher bulk imports: a plain full name acts as the
 * login (matched through the email blind index, see BlindIndexUserProvider),
 * and a header row is found by alias so the sheet layout can vary.
 */
trait ImportsNameLogins
{
    /** Logins already handed out earlier in the current preview() call (not yet in the database). */
    private array $batchLogins = [];

    /**
     * Search the first ~10 rows for a header row containing the required field,
     * matching the same alias approach LearnerImportController uses for SF1 files.
     *
     * @param array<string, string[]> $aliases field => accepted header texts
     */
    private function detectHeaderRow(array $rows, array $aliases, string $requiredField): ?array
    {
        $maxScanRows = min(10, count($rows));

        for ($r = 0; $r < $maxScanRows; $r++) {
            $cells = $rows[$r] ?? [];
            $map = [];

            foreach ($cells as $col => $cell) {
                $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string) $cell)));
                if ($normalized === '') {
                    continue;
                }

                $bestField = null;
                $bestLen = 0;
                foreach ($aliases as $field => $fieldAliases) {
                    foreach ($fieldAliases as $alias) {
                        if (str_contains($normalized, $alias) && strlen($alias) > $bestLen) {
                            $bestField = $field;
                            $bestLen = strlen($alias);
                        }
                    }
                }

                if ($bestField !== null) {
                    $map[$bestField] = $col;
                }
            }

            if (isset($map[$requiredField])) {
                $map['_header_row'] = $r;
                return $map;
            }
        }

        return null;
    }

    /**
     * The person's own name, used as their login. The "email" column just holds
     * this string — BlindIndexUserProvider matches it case-insensitively, the
     * same way it would match a real email, so no email format is needed.
     *
     * Two people who share an exact name (same spelling, same case-folding)
     * get " (2)", " (3)", ... appended — tell that person to type the suffix
     * too; it's printed on their slip.
     */
    private function generateUniqueLogin(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $candidate = $name;
        $suffix = 2;

        while ($this->loginTaken($candidate)) {
            $candidate = "{$name} ({$suffix})";
            $suffix++;
        }

        $this->batchLogins[] = mb_strtolower($candidate);

        return $candidate;
    }

    /** True if this login is already in the database, or already handed to an earlier row in this same file. */
    private function loginTaken(string $candidate): bool
    {
        if (in_array(mb_strtolower($candidate), $this->batchLogins, true)) {
            return true;
        }

        return DB::table('users')->where('email_index', BlindIndex::make($candidate, 'email'))->exists();
    }
}
