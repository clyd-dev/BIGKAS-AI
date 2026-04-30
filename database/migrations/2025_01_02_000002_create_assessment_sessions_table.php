<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('reading_materials')->cascadeOnDelete();
            $table->string('session_code', 10)->unique();
            $table->enum('status', ['waiting', 'ready', 'reading', 'recording', 'completed', 'cancelled'])->default('waiting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('student_joined_at')->nullable();
            $table->timestamp('reading_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('elapsed_seconds')->default(0);
            $table->json('teacher_notes')->nullable();
            $table->json('student_progress')->nullable(); // current word index, scroll position, etc.
            $table->timestamps();

            $table->index('session_code');
            $table->index(['learner_id', 'status']);
            $table->index(['teacher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_sessions');
    }
};
