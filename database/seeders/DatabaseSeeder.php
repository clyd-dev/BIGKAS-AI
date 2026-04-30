<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SchoolSeeder::class,
            UserSeeder::class,
            ClassSeeder::class,
            LearnerSeeder::class,
            ReadingMaterialSeeder::class,
            ComprehensionQuestionSeeder::class,
            InterventionSeeder::class,
            SystemSettingSeeder::class,
            BadgeSeeder::class,
        ]);
    }
}
