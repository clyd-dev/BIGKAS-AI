<?php

use App\Support\BlindIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step A of encrypting personal data at rest: who people are.
 *
 *   learners: first_name, last_name, middle_name, lrn, birth_date, mother_tongue, notes
 *   users:    name, email, phone
 *
 * Encrypted values are long and cannot be indexed, so those columns become TEXT, the old indexes on them are
 * dropped, and two keyed "blind index" columns (users.email_index, learners.lrn_index) take over the exact-match
 * lookups and the uniqueness checks. Existing rows are encrypted in place; re-running is safe.
 */
return new class extends Migration
{
    private const LEARNER_COLUMNS = ['first_name', 'last_name', 'middle_name', 'lrn', 'birth_date', 'mother_tongue', 'notes'];
    private const USER_COLUMNS    = ['name', 'email', 'phone'];

    public function up(): void
    {
        // 1. Drop the indexes that cannot work on encrypted text.
        Schema::table('users', fn (Blueprint $t) => $t->dropUnique('users_email_unique'));
        Schema::table('learners', function (Blueprint $t) {
            $t->dropUnique('learners_lrn_unique');
            $t->dropIndex('learners_last_name_first_name_index');
        });

        // 2. Widen the columns.
        Schema::table('users', function (Blueprint $t) {
            $t->text('name')->change();
            $t->text('email')->change();
            $t->text('phone')->nullable()->change();
        });
        Schema::table('learners', function (Blueprint $t) {
            $t->text('first_name')->change();
            $t->text('last_name')->change();
            $t->text('middle_name')->nullable()->change();
            $t->text('lrn')->nullable()->change();
            $t->text('birth_date')->nullable()->change();
            $t->text('mother_tongue')->nullable()->change();
            $t->text('notes')->nullable()->change();
        });

        // 3. Blind index columns.
        Schema::table('users', fn (Blueprint $t) => $t->char('email_index', 64)->nullable()->after('email'));
        Schema::table('learners', fn (Blueprint $t) => $t->char('lrn_index', 64)->nullable()->after('lrn'));

        // 4. Encrypt existing rows in place and fill the indexes.
        DB::table('users')->orderBy('id')->each(function ($row) {
            $update = $this->encryptColumns($row, self::USER_COLUMNS);
            $update['email_index'] = BlindIndex::make($this->plain($row->email), 'email');
            DB::table('users')->where('id', $row->id)->update($update);
        });
        DB::table('learners')->orderBy('id')->each(function ($row) {
            $update = $this->encryptColumns($row, self::LEARNER_COLUMNS);
            $update['lrn_index'] = BlindIndex::make($this->plain($row->lrn), 'lrn');
            DB::table('learners')->where('id', $row->id)->update($update);
        });

        // 5. Uniqueness now lives on the blind indexes.
        Schema::table('users', fn (Blueprint $t) => $t->unique('email_index'));
        Schema::table('learners', fn (Blueprint $t) => $t->unique('lrn_index'));
    }

    /** Best effort: write the readable values back. Column types and the dropped indexes are not restored. */
    public function down(): void
    {
        DB::table('users')->orderBy('id')->each(function ($row) {
            DB::table('users')->where('id', $row->id)->update($this->decryptColumns($row, self::USER_COLUMNS));
        });
        DB::table('learners')->orderBy('id')->each(function ($row) {
            DB::table('learners')->where('id', $row->id)->update($this->decryptColumns($row, self::LEARNER_COLUMNS));
        });

        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['email_index']);
            $t->dropColumn('email_index');
        });
        Schema::table('learners', function (Blueprint $t) {
            $t->dropUnique(['lrn_index']);
            $t->dropColumn('lrn_index');
        });
    }

    private function encryptColumns(object $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $value = $row->{$col};
            if ($value !== null && $value !== '' && ! $this->isEncrypted($value)) {
                $out[$col] = Crypt::encryptString((string) $value);
            }
        }

        return $out;
    }

    private function decryptColumns(object $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $value = $row->{$col};
            if ($value !== null && $this->isEncrypted($value)) {
                $out[$col] = Crypt::decryptString($value);
            }
        }

        return $out;
    }

    private function plain(?string $value): ?string
    {
        return $value !== null && $this->isEncrypted($value) ? Crypt::decryptString($value) : $value;
    }

    /** A Laravel payload is base64 of {"iv":...}; a name, email or date never looks like that. */
    private function isEncrypted(string $value): bool
    {
        return str_starts_with($value, 'eyJpdiI6');
    }
};
