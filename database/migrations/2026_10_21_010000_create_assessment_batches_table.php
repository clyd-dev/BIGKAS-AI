<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch is one teacher assessing a roster of children in one go. It holds
 * only what the children share (class, language, type, default passage) and
 * its own lifecycle; each child on the roster is an ordinary assessment that
 * points back here through assessments.batch_id (next migration).
 *
 * The foreign keys mirror assessments: a teacher with history and a material
 * that is cited are never silently deleted, while a deleted class just leaves
 * the batch behind as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('classes')->nullOnDelete()
                ->comment('null for a hand-picked batch');
            $table->string('title')->nullable()->comment("the teacher's own label");
            $table->string('language', 8)->comment('en | fil | hil');
            $table->string('assessment_type', 16)->comment('oral_reading | comprehension | combined');
            $table->foreignId('default_material_id')->constrained('reading_materials')->restrictOnDelete();
            $table->string('status', 16)->comment('collecting | analyzing | completed | cancelled');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('teacher_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_batches');
    }
};
