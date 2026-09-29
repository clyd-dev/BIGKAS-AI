<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'settings' => [
                'app_name' => 'BIGKAS-Test',
                'max_audio_mb' => 30,
                'independent_threshold' => 97,
                'instructional_threshold' => 90,
                'ml_enabled' => '1',
                'auto_recommend' => '1',
            ],
        ], $overrides);
    }

    public function test_save_persists_per_key_values_and_types(): void
    {
        $this->actingAs($this->admin())->post(route('admin.settings.save'), $this->payload())
            ->assertRedirect();

        $this->assertSame('BIGKAS-Test', SystemSetting::getValue('app_name'));
        $this->assertSame(30.0, SystemSetting::getValue('max_audio_mb'));
        $this->assertSame(97.0, SystemSetting::getValue('independent_threshold'));
        $this->assertTrue(SystemSetting::getValue('ml_enabled'));
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'settings']);
    }

    public function test_reload_shows_saved_values_not_defaults(): void
    {
        $this->actingAs($this->admin())->post(route('admin.settings.save'), $this->payload());

        $response = $this->actingAs($this->admin())->get(route('admin.settings'));

        $response->assertOk();
        $response->assertSee('value="BIGKAS-Test"', false);
        $response->assertSee('value="30"', false);
    }

    public function test_unchecked_boxes_store_false(): void
    {
        $settings = $this->payload()['settings'];
        unset($settings['ml_enabled'], $settings['auto_recommend']);

        $this->actingAs($this->admin())->post(route('admin.settings.save'), ['settings' => $settings]);

        $this->assertFalse(SystemSetting::getValue('ml_enabled'));
        $this->assertFalse(SystemSetting::getValue('auto_recommend'));
        $this->actingAs($this->admin())->get(route('admin.settings'))->assertOk();
    }

    public function test_invalid_thresholds_rejected_and_nothing_saved(): void
    {
        $settings = $this->payload()['settings'];
        $settings['instructional_threshold'] = 98;

        $this->actingAs($this->admin())->post(route('admin.settings.save'), ['settings' => $settings])
            ->assertSessionHasErrors('settings.instructional_threshold');
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'app_name']);
    }

    public function test_unknown_keys_ignored(): void
    {
        $payload = $this->payload();
        $payload['settings']['evil'] = '1';

        $this->actingAs($this->admin())->post(route('admin.settings.save'), $payload)
            ->assertRedirect();
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'evil']);
    }
}
