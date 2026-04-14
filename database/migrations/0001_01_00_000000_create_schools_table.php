<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('school_id_number', 50)->nullable()->unique();
            $table->text('address')->nullable();
            $table->string('district', 100)->nullable()->default('Sagay City');
            $table->string('division', 100)->nullable()->default('Negros Occidental');
            $table->string('region', 100)->nullable()->default('Region VI - Western Visayas');
            $table->string('contact_number', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('principal_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('district');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
