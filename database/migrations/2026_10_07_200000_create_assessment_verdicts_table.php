<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The teacher's decision about an AI result.
     *
     * BIGKAS is a decision support system: the model advises, the teacher
     * decides. The AI's own numbers in assessment_results are never rewritten —
     * the teacher's verdict lives here alongside them, so the record always
     * shows both what the model predicted and what the teacher concluded.
     */
    public function up(): void
    {
        Schema::create('assessment_verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->unique()->constrained('assessments')->cascadeOnDelete();

            // accepted    — teacher agrees with the AI result
            // overridden  — teacher replaces the judgement (level / weakness / manual scoring)
            // invalidated — result can't be trusted; kept on record, excluded from the
            //               learner's computed level until a re-assessment is done
            $table->enum('decision', ['accepted', 'overridden', 'invalidated']);

            $table->enum('final_reading_level', ['frustration', 'instructional', 'independent'])->nullable();
            $table->tinyInteger('final_primary_weakness')->unsigned()->nullable();

            // Optional manual (Phil-IRI style) re-scoring. When present, accuracy
            // and WPM below are recomputed from these counts.
            $table->boolean('manual_scoring')->default(false);
            $table->unsignedSmallInteger('words_read')->nullable();
            $table->unsignedSmallInteger('manual_substitutions')->nullable();
            $table->unsignedSmallInteger('manual_omissions')->nullable();
            $table->unsignedSmallInteger('manual_insertions')->nullable();
            $table->unsignedSmallInteger('manual_self_corrections')->nullable();
            $table->decimal('final_accuracy_rate', 5, 2)->nullable();
            $table->decimal('final_words_per_minute', 6, 2)->nullable();

            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_verdicts');
    }
};
