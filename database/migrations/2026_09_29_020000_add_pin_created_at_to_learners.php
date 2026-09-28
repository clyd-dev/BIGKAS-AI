<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add PIN issuance timestamp to learners (Task 14).
     * Nullable so legacy rows (PIN issued before this column existed)
     * are treated as NOT expired by Learner::isPinExpired().
     */
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table) {
            $table->timestamp('pin_created_at')->nullable()->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table) {
            $table->dropColumn('pin_created_at');
        });
    }
};
