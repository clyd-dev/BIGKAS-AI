<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reading_materials', function (Blueprint $table) {
            $table->enum('type', ['oral_reading', 'comprehension'])->default('oral_reading')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('reading_materials', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
