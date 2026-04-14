<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->tinyInteger('grade_level')->unsigned();
            $table->string('section', 100);
            $table->string('school_year', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('grade_level');
            $table->index('school_year');
            $table->unique(['school_id', 'grade_level', 'section', 'school_year'], 'uk_class_section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
