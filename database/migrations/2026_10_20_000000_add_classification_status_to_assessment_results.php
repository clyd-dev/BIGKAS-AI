<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A weakness label now has an explicit outcome, and an unclassified result has
 * no confidence rather than an invented one.
 *
 * `classification_status` is left NULL on every existing row on purpose: those
 * results predate the column, keep their label, and are shown as legacy with
 * the warning that their source was not (or not reliably) recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_results', function (Blueprint $table) {
            $table->string('classification_status', 24)
                ->nullable()
                ->after('confidence_score')
                ->comment('classified | model_unsupported | unavailable; NULL = saved before this was tracked');

            $table->index('classification_status');
        });

        // The old default of 0.50 stamped a made-up confidence on any result
        // that did not supply one.
        Schema::table('assessment_results', function (Blueprint $table) {
            $table->decimal('confidence_score', 3, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('assessment_results')->whereNull('confidence_score')->update(['confidence_score' => 0.50]);

        Schema::table('assessment_results', function (Blueprint $table) {
            $table->decimal('confidence_score', 3, 2)->nullable(false)->default(0.50)->change();
        });

        Schema::table('assessment_results', function (Blueprint $table) {
            $table->dropIndex(['classification_status']);
            $table->dropColumn('classification_status');
        });
    }
};
