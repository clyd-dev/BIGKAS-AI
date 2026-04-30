<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name');
            $table->text('description');
            $table->string('icon', 10);           // emoji icon
            $table->string('color', 20);           // CSS color class
            $table->string('category', 30);        // assessment, practice, streak, milestone
            $table->unsignedInteger('xp_reward')->default(0);
            $table->json('criteria')->nullable();  // e.g. {"type":"assessment_count","value":5}
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('learner_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('badges')->cascadeOnDelete();
            $table->timestamp('earned_at');
            $table->string('context')->nullable(); // e.g. "assessment #12"

            $table->unique(['learner_id', 'badge_id']);
            $table->index('learner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_badges');
        Schema::dropIfExists('badges');
    }
};
