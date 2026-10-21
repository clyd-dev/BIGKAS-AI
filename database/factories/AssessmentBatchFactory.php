<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\ReadingMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentBatch>
 */
class AssessmentBatchFactory extends Factory
{
    protected $model = AssessmentBatch::class;

    public function definition(): array
    {
        return [
            'teacher_id' => User::factory()->state(['role' => User::ROLE_TEACHER]),
            'school_class_id' => null,
            'title' => fake()->words(3, true),
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'default_material_id' => ReadingMaterial::factory(),
            'status' => AssessmentBatch::STATUS_COLLECTING,
        ];
    }
}
