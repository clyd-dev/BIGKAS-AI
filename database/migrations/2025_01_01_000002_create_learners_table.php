<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learners', function (Blueprint $table) {
            $table->id();
            $table->string('lrn', 20)->nullable()->unique()->comment('Learner Reference Number (DepEd)');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->tinyInteger('grade_level')->unsigned()->default(1);
            $table->enum('reading_level', ['frustration', 'instructional', 'independent'])->nullable();
            $table->string('mother_tongue', 50)->nullable()->default('Filipino');
            $table->text('notes')->nullable();
            $table->string('avatar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('grade_level');
            $table->index('reading_level');
            $table->index('school_id');
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('learner_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('relationship', ['teacher', 'parent', 'guardian', 'tutor'])->default('teacher');
            $table->timestamps();

            $table->unique(['learner_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_user');
        Schema::dropIfExists('learners');
    }
};
