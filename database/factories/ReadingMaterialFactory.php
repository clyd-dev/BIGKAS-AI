<?php

namespace Database\Factories;

use App\Models\ReadingMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingMaterial>
 */
class ReadingMaterialFactory extends Factory
{
    protected $model = ReadingMaterial::class;

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'content' => 'I have a dog his name is Max Max is brown he has a long tail',
            'language' => 'en',
            'grade_level' => 4,
            'difficulty' => ReadingMaterial::DIFFICULTY_EASY,
            'word_count' => 15,
            'type' => ReadingMaterial::TYPE_ORAL_READING,
            'is_active' => true,
        ];
    }
}
