<?php

use App\Models\Learner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen learners.pin to hold bcrypt hashes and hash any
     * existing plaintext PINs. PINs already starting with
     * '$2y$' are assumed hashed and left untouched.
     */
    public function up(): void
    {
        // No doctrine/dbal installed, so widen via driver-specific DDL.
        // SQLite ignores VARCHAR lengths, so no DDL change is needed there.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `learners` MODIFY `pin` VARCHAR(255) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "learners" ALTER COLUMN "pin" TYPE VARCHAR(255)');
        }

        Learner::whereNotNull('pin')->chunkById(200, function ($learners) {
            foreach ($learners as $learner) {
                $raw = $learner->getRawOriginal('pin');

                if ($raw !== null && ! str_starts_with($raw, '$2y$')) {
                    // The 'hashed' cast on Learner::pin hashes this on save.
                    $learner->update(['pin' => $raw]);
                }
            }
        });
    }

    /**
     * Do not shrink the column: hashed PINs would be truncated.
     */
    public function down(): void
    {
        //
    }
};
