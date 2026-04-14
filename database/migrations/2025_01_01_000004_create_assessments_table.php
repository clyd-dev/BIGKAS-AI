<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('reading_materials')->restrictOnDelete();
            $table->foreignId('assessor_id')->constrained('users')->restrictOnDelete()->comment('Teacher or parent who administered');
            $table->string('audio_file')->nullable()->comment('Recorded audio filename');
            $table->json('transcription')->nullable()->comment('Speech-to-text result JSON');
            $table->enum('language', ['en', 'fil', 'hil'])->default('en');
            $table->enum('status', ['pending', 'recording', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamp('assessed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('learner_id');
            $table->index('assessor_id');
            $table->index('status');
            $table->index('assessed_at');
        });

        Schema::create('assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->unique()->constrained('assessments')->cascadeOnDelete();

            // Reading metrics
            $table->decimal('accuracy_rate', 5, 2)->default(0)->comment('Percentage 0-100');
            $table->decimal('words_per_minute', 6, 1)->default(0);
            $table->unsignedInteger('error_count')->default(0);

            // Error breakdown
            $table->unsignedInteger('substitutions')->default(0);
            $table->unsignedInteger('omissions')->default(0);
            $table->unsignedInteger('insertions')->default(0);
            $table->unsignedInteger('repetitions')->default(0);
            $table->unsignedInteger('self_corrections')->default(0);

            // Scores
            $table->decimal('fluency_score', 4, 1)->nullable()->comment('0-10 scale');
            $table->decimal('prosody_score', 4, 1)->nullable()->comment('0-10 scale');
            $table->decimal('comprehension_score', 5, 2)->nullable()->comment('0-100 percentage');

            // Classification
            $table->enum('reading_level', ['frustration', 'instructional', 'independent'])->default('frustration');
            $table->tinyInteger('primary_weakness')->unsigned()->nullable()->comment('1=Phonemic, 2=Decoding, 3=Fluency, 4=Comprehension');
            $table->tinyInteger('secondary_weakness')->unsigned()->nullable();
            $table->decimal('confidence_score', 3, 2)->default(0.50)->comment('0.00-1.00');

            // Full ML analysis JSON
            $table->json('ml_analysis_json')->nullable()->comment('Complete ML output for detailed views');

            $table->timestamps();

            $table->index('reading_level');
            $table->index('primary_weakness');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
    }
};
