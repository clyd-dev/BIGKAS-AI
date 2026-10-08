<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Student PINs are now stored encrypted (readable by the teacher), not hashed.
     *
     * - Plain 6-digit PINs (never hashed) are encrypted.
     * - Bcrypt hashes ("$2y$...") cannot be reversed. They are left as they are: login still works with them and
     *   each is converted to an encrypted PIN the next time the child logs in, or when the teacher issues a new PIN.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `learners` MODIFY `pin` VARCHAR(255) NULL');
        }

        DB::table('learners')->whereNotNull('pin')->orderBy('id')->each(function ($row) {
            if (preg_match('/^\d{6}$/', (string) $row->pin)) {
                DB::table('learners')->where('id', $row->id)->update(['pin' => Crypt::encryptString($row->pin)]);
            }
        });
    }

    /** Encrypted PINs cannot be turned back into hashes; nothing to undo. */
    public function down(): void
    {
        //
    }
};
