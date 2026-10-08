<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Record of a Phil-IRI Form 2 (School Reading Profile) the principal sent to DepEd.
        Schema::create('deped_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('school_year', 20);
            $table->enum('period', ['pre_test', 'post_test']);
            $table->json('form_data');                    // the consolidated Form 2 as it was submitted
            $table->date('submitted_on');
            $table->string('reference', 120)->nullable(); // receiving office / transmittal / tracking no.
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_year', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deped_submissions');
    }
};
