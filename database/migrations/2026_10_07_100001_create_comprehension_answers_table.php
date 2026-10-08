<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprehension_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();

            // Nullable + nullOnDelete so editing or removing a material's
            // questions later never destroys a past assessment's record.
            $table->foreignId('question_id')->nullable()
                ->constrained('comprehension_questions')->nullOnDelete();

            // Snapshots of what was actually asked and answered at the time.
            $table->text('question_text');
            $table->char('selected_option', 1)->nullable()->comment('A-D');
            $table->text('selected_text')->nullable();
            $table->text('correct_text');
            $table->boolean('is_correct')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->unique(['assessment_id', 'question_id']);
            $table->index('assessment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprehension_answers');
    }
};
