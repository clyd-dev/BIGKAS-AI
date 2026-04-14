<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('reading_materials')->nullOnDelete();
            $table->enum('session_type', ['phonemic', 'sight_words', 'guided_reading', 'comprehension']);
            $table->decimal('score', 5, 2)->nullable()->comment('Percentage 0-100');
            $table->unsignedInteger('time_spent')->default(0)->comment('Seconds');
            $table->json('details')->nullable()->comment('Session-specific data');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('learner_id');
            $table->index('session_type');
            $table->index('created_at');
        });

        Schema::create('progress_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->decimal('phonemic_awareness_score', 5, 2)->nullable();
            $table->decimal('decoding_score', 5, 2)->nullable();
            $table->decimal('fluency_score', 5, 2)->nullable();
            $table->decimal('comprehension_score', 5, 2)->nullable();
            $table->enum('reading_level', ['frustration', 'instructional', 'independent'])->nullable();
            $table->decimal('words_per_minute', 6, 1)->nullable();
            $table->unsignedInteger('assessments_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['learner_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_snapshots');
        Schema::dropIfExists('practice_sessions');
    }
};
