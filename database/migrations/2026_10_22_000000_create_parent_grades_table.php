<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which grade level(s) a parent account belongs to. Teachers only see/assign the
 * parents of their own grade, so a Grade 4 teacher never lists a Grade 3 parent.
 * A parent with children in Grades 3 and 4 simply has two rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('grade_level');
            $table->timestamps();

            $table->unique(['user_id', 'grade_level']);
        });

        // Backfill from existing parent <-> learner links so nobody disappears from their teacher.
        $rows = DB::table('learner_user')
            ->join('users', 'users.id', '=', 'learner_user.user_id')
            ->join('learners', 'learners.id', '=', 'learner_user.learner_id')
            ->where('users.role', 'parent')
            ->whereNotNull('learners.grade_level')
            ->select('learner_user.user_id', 'learners.grade_level')
            ->distinct()
            ->get();

        $now = now();
        foreach ($rows as $row) {
            DB::table('parent_grades')->insertOrIgnore([
                'user_id'     => $row->user_id,
                'grade_level' => $row->grade_level,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_grades');
    }
};
