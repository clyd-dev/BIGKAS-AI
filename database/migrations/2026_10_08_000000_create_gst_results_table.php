<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phil-IRI Group Screening Test (Forms 1A Filipino / 1B English).
        Schema::create('gst_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('school_year', 20);
            $table->enum('period', ['pre_test', 'post_test']);
            $table->enum('language', ['fil', 'en']);
            $table->unsignedTinyInteger('test_level');           // always the learner's grade
            $table->boolean('test_taken')->default(true);
            $table->unsignedTinyInteger('literal_correct')->nullable();
            $table->unsignedTinyInteger('inferential_correct')->nullable();
            $table->unsignedTinyInteger('critical_correct')->nullable();
            $table->unsignedTinyInteger('total_score')->nullable();
            $table->enum('classification', ['at_grade_level', 'needs_assessment'])->nullable();
            $table->unsignedTinyInteger('starting_level')->nullable(); // 0 = Kindergarten, null = discontinue
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['learner_id', 'school_year', 'period', 'language'], 'gst_unique_entry');
            $table->index(['class_id', 'school_year', 'period', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_results');
    }
};
