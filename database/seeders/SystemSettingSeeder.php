<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['setting_key' => 'app_name', 'setting_value' => 'BIGKAS-AI', 'setting_type' => 'string', 'description' => 'Application display name'],
            ['setting_key' => 'max_audio_mb', 'setting_value' => '20', 'setting_type' => 'number', 'description' => 'Maximum audio file size in MB'],
            ['setting_key' => 'independent_threshold', 'setting_value' => '97', 'setting_type' => 'number', 'description' => 'Independent reading level threshold'],
            ['setting_key' => 'instructional_threshold', 'setting_value' => '90', 'setting_type' => 'number', 'description' => 'Instructional reading level threshold'],
            ['setting_key' => 'ml_enabled', 'setting_value' => 'true', 'setting_type' => 'boolean', 'description' => 'Enable ML classification service'],
            ['setting_key' => 'auto_recommend', 'setting_value' => 'true', 'setting_type' => 'boolean', 'description' => 'Enable automatic intervention recommendations'],
            ['setting_key' => 'school_year', 'setting_value' => '2025-2026', 'setting_type' => 'string', 'description' => 'Current school year'],
            ['setting_key' => 'assessment_time_limit', 'setting_value' => '180', 'setting_type' => 'number', 'description' => 'Maximum recording time in seconds'],
            ['setting_key' => 'min_words_for_assessment', 'setting_value' => '30', 'setting_type' => 'number', 'description' => 'Minimum word count for assessment passages'],
            ['setting_key' => 'enable_practice_center', 'setting_value' => 'true', 'setting_type' => 'boolean', 'description' => 'Enable learner practice center'],
            ['setting_key' => 'enable_comprehension_questions', 'setting_value' => 'true', 'setting_type' => 'boolean', 'description' => 'Enable comprehension questions after assessment'],
            ['setting_key' => 'wpm_threshold_frustration', 'setting_value' => '50', 'setting_type' => 'number', 'description' => 'WPM below this is frustration level'],
        ];

        SystemSetting::whereIn('setting_key', ['max_audio_size_mb', 'ml_service_enabled', 'default_language'])->delete();

        foreach ($settings as $setting) {
            SystemSetting::firstOrCreate(['setting_key' => $setting['setting_key']], $setting);
        }
    }
}
