<?php

namespace Tests\Feature;

use App\Models\ReadingMaterial;
use Database\Seeders\ReadingMaterialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Reading assessment supports English and Filipino only.
 */
class LanguageSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_english_and_filipino_are_configured(): void
    {
        $this->assertSame(['en', 'fil'], config('bigkas.locales'));
        $this->assertArrayNotHasKey('hil', config('services.google_speech.languages'));
    }

    public function test_seeded_materials_are_english_or_filipino(): void
    {
        $this->seed(ReadingMaterialSeeder::class);

        $this->assertGreaterThan(0, ReadingMaterial::count());
        $this->assertSame(0, ReadingMaterial::whereNotIn('language', ['en', 'fil'])->count());
    }

    public function test_the_database_refuses_a_hiligaynon_material(): void
    {
        $this->expectException(QueryException::class);

        ReadingMaterial::query()->insert([
            'title' => 'Ang Akon Ido', 'content' => 'May ido ako.', 'language' => 'hil',
            'grade_level' => 1, 'difficulty' => 'easy', 'word_count' => 3, 'category' => 'narrative',
        ]);
    }
}
