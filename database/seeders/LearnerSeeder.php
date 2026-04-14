<?php

namespace Database\Seeders;

use App\Models\Learner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LearnerSeeder extends Seeder
{
    public function run(): void
    {
        $learners = [
            ['lrn' => '100100100001', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'middle_name' => 'Santos', 'birth_date' => '2018-03-15', 'gender' => 'female', 'class_id' => 1, 'school_id' => 1, 'grade_level' => 1, 'mother_tongue' => 'Hiligaynon'],
            ['lrn' => '100100100002', 'first_name' => 'Ben', 'last_name' => 'Garcia', 'middle_name' => 'Cruz', 'birth_date' => '2018-07-22', 'gender' => 'male', 'class_id' => 1, 'school_id' => 1, 'grade_level' => 1, 'mother_tongue' => 'Hiligaynon'],
            ['lrn' => '100100100003', 'first_name' => 'Carla', 'last_name' => 'Lopez', 'middle_name' => 'Mendoza', 'birth_date' => '2017-11-05', 'gender' => 'female', 'class_id' => 2, 'school_id' => 1, 'grade_level' => 2, 'mother_tongue' => 'Hiligaynon'],
            ['lrn' => '100100100004', 'first_name' => 'David', 'last_name' => 'Santos', 'middle_name' => 'Ramos', 'birth_date' => '2017-01-18', 'gender' => 'male', 'class_id' => 2, 'school_id' => 1, 'grade_level' => 2, 'mother_tongue' => 'Hiligaynon'],
            ['lrn' => '100100100005', 'first_name' => 'Elena', 'last_name' => 'Cruz', 'middle_name' => 'Torres', 'birth_date' => '2016-09-10', 'gender' => 'female', 'class_id' => 3, 'school_id' => 1, 'grade_level' => 3, 'mother_tongue' => 'Hiligaynon'],
            ['lrn' => '100100100006', 'first_name' => 'Francis', 'last_name' => 'Dela Cruz', 'middle_name' => 'Bautista', 'birth_date' => '2012-04-20', 'gender' => 'male', 'class_id' => 4, 'school_id' => 4, 'grade_level' => 7, 'mother_tongue' => 'Hiligaynon'],
            ['lrn' => '100100100007', 'first_name' => 'Grace', 'last_name' => 'Rivera', 'middle_name' => 'Aquino', 'birth_date' => '2012-08-30', 'gender' => 'female', 'class_id' => 4, 'school_id' => 4, 'grade_level' => 7, 'mother_tongue' => 'Hiligaynon'],
            ['lrn' => '100100100008', 'first_name' => 'Henry', 'last_name' => 'Villanueva', 'middle_name' => 'Reyes', 'birth_date' => '2018-05-12', 'gender' => 'male', 'class_id' => 1, 'school_id' => 1, 'grade_level' => 1, 'mother_tongue' => 'Hiligaynon'],
        ];

        foreach ($learners as $learner) {
            Learner::create($learner);
        }

        // Link learners to users (teacher=2 teaches all, parent=3 has learners 1 & 2)
        $pivotData = [
            ['learner_id' => 1, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 2, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 3, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 4, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 5, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 6, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 7, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 8, 'user_id' => 2, 'relationship' => 'teacher'],
            ['learner_id' => 1, 'user_id' => 3, 'relationship' => 'parent'],
            ['learner_id' => 2, 'user_id' => 3, 'relationship' => 'parent'],
        ];

        foreach ($pivotData as $pivot) {
            DB::table('learner_user')->insert(array_merge($pivot, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
