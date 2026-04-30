<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table) {
            $table->string('pin', 6)->nullable()->unique()->after('mother_tongue');
            $table->unsignedInteger('current_streak')->default(0)->after('is_active');
            $table->unsignedInteger('longest_streak')->default(0)->after('current_streak');
            $table->unsignedInteger('total_xp')->default(0)->after('longest_streak');
            $table->date('last_activity_date')->nullable()->after('total_xp');
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table) {
            $table->dropColumn(['pin', 'current_streak', 'longest_streak', 'total_xp', 'last_activity_date']);
        });
    }
};
