<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an assessment belong to a batch, and records when it was sent for
 * analysis and how long that took. All columns are nullable: NULL batch_id
 * means a single-child assessment, exactly as before.
 *
 *  - batch_id is nullOnDelete: deleting a batch must never delete a child's
 *    assessment or result.
 *  - submitted_for_analysis_at is the Send gate, separating recorded from queued.
 *  - analysis_started_at set with analysis_duration_ms still null is the
 *    transcribing state; analysis_duration_ms feeds the time estimator.
 *
 * The composite index serves the rescore queue's batch branch, which filters
 * on both columns every scheduler tick. The timing columns are not indexed:
 * the estimator reads the last rows by id and the transcribing check is
 * already narrowed by batch_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('assessor_id')
                ->constrained('assessment_batches')->nullOnDelete();
            $table->timestamp('submitted_for_analysis_at')->nullable()->after('last_rescore_at');
            $table->timestamp('analysis_started_at')->nullable()->after('submitted_for_analysis_at');
            $table->unsignedInteger('analysis_duration_ms')->nullable()->after('analysis_started_at');

            $table->index('batch_id');
            $table->index(['batch_id', 'submitted_for_analysis_at']);
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['batch_id', 'submitted_for_analysis_at']);
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['batch_id', 'submitted_for_analysis_at', 'analysis_started_at', 'analysis_duration_ms']);
        });
    }
};
