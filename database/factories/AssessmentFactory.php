<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'material_id' => ReadingMaterial::factory(),
            'assessor_id' => User::factory()->state(['role' => User::ROLE_TEACHER]),
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ];
    }
}
