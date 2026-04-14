<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_materials', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->enum('language', ['en', 'fil', 'hil'])->default('en');
            $table->tinyInteger('grade_level')->unsigned()->default(1);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->unsignedInteger('word_count')->default(0);
            $table->enum('category', ['narrative', 'expository', 'poetry', 'dialogue'])->default('narrative');
            $table->string('source')->nullable()->comment('Where the material came from');
            $table->string('audio_guide')->nullable()->comment('Filename of model reading audio');
            $table->string('image')->nullable();
            $table->string('genre', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('language');
            $table->index('grade_level');
            $table->index('difficulty');
            $table->index('is_active');
        });

        Schema::create('comprehension_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('reading_materials')->cascadeOnDelete();
            $table->text('question');
            $table->enum('question_type', ['literal', 'inferential', 'evaluative', 'applied'])->default('literal');
            $table->text('correct_answer');
            $table->string('option_a')->nullable();
            $table->string('option_b')->nullable();
            $table->string('option_c')->nullable();
            $table->string('option_d')->nullable();
            $table->tinyInteger('sort_order')->unsigned()->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprehension_questions');
        Schema::dropIfExists('reading_materials');
    }
};
