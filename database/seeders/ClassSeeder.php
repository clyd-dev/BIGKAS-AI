<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [
            ['school_id' => 1, 'teacher_id' => 2, 'grade_level' => 1, 'section' => 'Rose', 'school_year' => '2025-2026'],
            ['school_id' => 1, 'teacher_id' => 2, 'grade_level' => 2, 'section' => 'Sampaguita', 'school_year' => '2025-2026'],
            ['school_id' => 1, 'teacher_id' => 2, 'grade_level' => 3, 'section' => 'Orchid', 'school_year' => '2025-2026'],
            ['school_id' => 4, 'teacher_id' => 2, 'grade_level' => 7, 'section' => 'Einstein', 'school_year' => '2025-2026'],
        ];

        foreach ($classes as $class) {
            SchoolClass::create($class);
        }
    }
}
