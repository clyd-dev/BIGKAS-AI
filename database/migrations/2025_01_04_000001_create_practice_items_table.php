<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_items', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['sight_word', 'phonemic_sound', 'rhyme', 'syllable', 'blend']);
            $table->string('content');
            $table->tinyInteger('grade_level')->unsigned()->default(1);
            $table->enum('language', ['en', 'fil'])->default('en');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'grade_level', 'language', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_items');
    }
};
