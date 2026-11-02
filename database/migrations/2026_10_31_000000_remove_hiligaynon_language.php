<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reading assessment is English and Filipino only. The first migrations still
 * allowed 'hil' in the language enums, and the seeder shipped three Hiligaynon
 * passages, so a database created before this change may carry both.
 *
 * Fresh installs already get the two-value enums from the edited create
 * migrations; this brings an existing MySQL database in line. It refuses to
 * run if any assessment or batch was actually recorded in Hiligaynon, because
 * that would mean throwing a child's recording away.
 */
return new class extends Migration
{
    public function up(): void
    {
        $materialIds = DB::table('reading_materials')->where('language', 'hil')->pluck('id');

        $inUse = DB::table('assessments')->where('language', 'hil')->exists()
            || DB::table('assessment_batches')->where('language', 'hil')->exists()
            || ($materialIds->isNotEmpty() && (
                DB::table('assessments')->whereIn('material_id', $materialIds)->exists()
                || DB::table('assessment_batches')->whereIn('default_material_id', $materialIds)->exists()
            ));

        if ($inUse) {
            throw new RuntimeException(
                'Hiligaynon is still used by recorded assessments or batches. Review or move them before running this migration.'
            );
        }

        DB::table('reading_materials')->where('language', 'hil')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reading_materials MODIFY language ENUM('en','fil') NOT NULL DEFAULT 'en'");
            DB::statement("ALTER TABLE assessments MODIFY language ENUM('en','fil') NOT NULL DEFAULT 'en'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reading_materials MODIFY language ENUM('en','fil','hil') NOT NULL DEFAULT 'en'");
            DB::statement("ALTER TABLE assessments MODIFY language ENUM('en','fil','hil') NOT NULL DEFAULT 'en'");
        }
    }
};
