<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->tinyInteger('target_weakness')->unsigned()->comment('1=Phonemic, 2=Decoding, 3=Fluency, 4=Comprehension');
            $table->enum('activity_type', ['game', 'drill', 'reading', 'writing', 'audio', 'visual'])->default('drill');
            $table->text('materials_needed')->nullable();
            $table->text('instructions');
            $table->boolean('for_teacher')->default(true);
            $table->boolean('for_parent')->default(true);
            $table->tinyInteger('grade_level_min')->unsigned()->default(1);
            $table->tinyInteger('grade_level_max')->unsigned()->default(6);
            $table->unsignedInteger('estimated_duration')->default(15)->comment('Minutes');
            $table->decimal('effectiveness_score', 3, 1)->default(5.0)->comment('0-10 rating');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('target_weakness');
            $table->index('activity_type');
            $table->index(['grade_level_min', 'grade_level_max']);
        });

        Schema::create('intervention_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_result_id')->nullable()->constrained('assessment_results')->nullOnDelete();
            $table->foreignId('intervention_id')->constrained('interventions')->restrictOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->tinyInteger('effectiveness_rating')->unsigned()->nullable()->comment('1-10 teacher/parent rating');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('learner_id');
            $table->index('status');
            $table->index('intervention_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_logs');
        Schema::dropIfExists('interventions');
    }
};
