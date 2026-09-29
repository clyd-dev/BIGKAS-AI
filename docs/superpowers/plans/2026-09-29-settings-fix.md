# Admin Settings Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Admin → System Settings save and reload 6 settings correctly, dropping default_language.

**Architecture:** Whitelisted per-key validation in `saveSettings` with explicit types, plain-values array from `settings()`, minimal view fixes, seeder key alignment with stale-key cleanup.

**Tech Stack:** Laravel 12, PHP 8.2, Blade (`layouts.app`), PHPUnit feature tests.

## Global Constraints

- Touch only: `AdminController@settings/saveSettings`, `admin/settings.blade.php`, `SystemSettingSeeder`, new `SettingsTest`.
- Do not add new settings; do not wire settings into assessment/ML logic (explicit follow-up).
- No `default_language` anywhere in this feature (view, controller, seeder).
- Non-whitelisted POST keys are ignored, never stored.
- Unchecked boxes store `'0'`; validation failures save nothing.
- One behavior change per commit; run affected tests before every commit.

---

### Task 1: Controller contract + view + round-trip tests

**Files:**
- Modify: `app/Http/Controllers/AdminController.php:426-443`
- Modify: `resources/views/auth/../admin/settings.blade.php` (`resources/views/admin/settings.blade.php`, language select block lines 23-29, checkbox lines 50-59)
- Test: `tests/Feature/SettingsTest.php` (create)

**Interfaces:**
- Consumes: `SystemSetting::getValue(string $key, mixed $default): mixed`, `SystemSetting::setValue(string $key, mixed $value, ?string $type): void`
- Produces: `settings()` view data = plain PHP values array (strings/ints/bools, never Models); route names unchanged (`admin.settings`, `admin.settings.save`)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SettingsTest.php`:

```php
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
```

Run: `php artisan test --filter=SettingsTest`
Expected: FAIL (old code stores a `settings` blob; reload shows defaults).

- [ ] **Step 2: Implement the controller contract**

Replace `AdminController@settings` (lines 426-430) with:

```php
public function settings()
{
    $settings = [
        'app_name' => SystemSetting::getValue('app_name', 'BIGKAS-AI'),
        'max_audio_mb' => SystemSetting::getValue('max_audio_mb', 20),
        'independent_threshold' => SystemSetting::getValue('independent_threshold', 97),
        'instructional_threshold' => SystemSetting::getValue('instructional_threshold', 90),
        'ml_enabled' => SystemSetting::getValue('ml_enabled', true),
        'auto_recommend' => SystemSetting::getValue('auto_recommend', true),
    ];

    return view('admin.settings', compact('settings'));
}
```

Replace `saveSettings` (lines 432-443) with:

```php
public function saveSettings(Request $request)
{
    $validated = $request->validate([
        'settings.app_name' => 'required|string|max:100',
        'settings.max_audio_mb' => 'required|integer|min:1|max:100',
        'settings.independent_threshold' => 'required|integer|min:90|max:100',
        'settings.instructional_threshold' => 'required|integer|min:80|max:100|lt:settings.independent_threshold',
        'settings.ml_enabled' => 'sometimes|boolean',
        'settings.auto_recommend' => 'sometimes|boolean',
    ]);

    $types = [
        'app_name' => 'string',
        'max_audio_mb' => 'number',
        'independent_threshold' => 'number',
        'instructional_threshold' => 'number',
        'ml_enabled' => 'boolean',
        'auto_recommend' => 'boolean',
    ];

    $changedKeys = [];
    foreach ($validated['settings'] as $key => $value) {
        SystemSetting::setValue($key, $value, $types[$key]);
        $changedKeys[] = $key;
    }

    // Unchecked boxes submit nothing — store explicit false, never stale.
    foreach (['ml_enabled', 'auto_recommend'] as $key) {
        if (! array_key_exists($key, $validated['settings'])) {
            SystemSetting::setValue($key, '0', 'boolean');
            $changedKeys[] = $key;
        }
    }

    ActivityLog::log('admin_update_settings', 'Updated system settings: ' . implode(', ', $changedKeys), 'system_setting', null);

    return back()->with('success', 'Settings saved.');
}
```

- [ ] **Step 3: Fix the view**

In `resources/views/admin/settings.blade.php`: delete the Default Language select block (lines 23-29) entirely. Change the two checkbox `checked` expressions to ternary on plain values: `{{ ($settings['ml_enabled'] ?? true) ? 'checked' : '' }}` and the same for `auto_recommend`. Add `@error('settings.instructional_threshold')` and `@error('settings.independent_threshold')` hint divs under the threshold inputs:

```blade
@error('settings.instructional_threshold')
    <div class="text-danger small">{{ $message }}</div>
@enderror
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=SettingsTest`
Expected: 5 PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AdminController.php resources/views/admin/settings.blade.php tests/Feature/SettingsTest.php
git commit -m "fix(settings): make admin settings save and reload per-key values"
```

---

### Task 2: Seeder alignment + full verification

**Files:**
- Modify: `database/seeders/SystemSettingSeeder.php` (full row list)
- Test: append seeder idempotence test to `tests/Feature/SettingsTest.php`
- Run: full suite + `php artisan db:seed --class=SystemSettingSeeder` on dev DB

**Interfaces:**
- Consumes: Task 1 canonical keys/types/defaults
- Produces: converged dev DB; plan file committed

Explicit decision (deviates from spec §4 `updateOrCreate`): use `firstOrCreate` so re-seeding never clobbers values an admin already saved; convergence of key names is handled by explicit stale-key deletion. Same converged end state, safer.

- [ ] **Step 1: Write the failing seeder test (append to SettingsTest)**

```php
public function test_seeder_is_idempotent_and_clears_stale_keys(): void
{
    SystemSetting::create(['setting_key' => 'max_audio_size_mb', 'setting_value' => '25', 'setting_type' => 'number', 'description' => 'stale']);

    $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    $this->seed(\Database\Seeders\SystemSettingSeeder::class);

    $this->assertSame(1, SystemSetting::where('setting_key', 'max_audio_mb')->count());
    $this->assertDatabaseMissing('system_settings', ['setting_key' => 'max_audio_size_mb']);
    $this->assertDatabaseMissing('system_settings', ['setting_key' => 'ml_service_enabled']);
    $this->assertDatabaseMissing('system_settings', ['setting_key' => 'default_language']);
    $this->assertSame('20', SystemSetting::where('setting_key', 'max_audio_mb')->first()->setting_value);
}
```

Run: `php artisan test --filter=test_seeder_is_idempotent_and_clears_stale_keys`
Expected: FAIL (old seeder creates duplicates + keeps stale keys).

- [ ] **Step 2: Rewrite the seeder**

Replace the `$settings` array body in `database/seeders/SystemSettingSeeder.php` with the 6 canonical rows (keys/types/defaults from Task 1: `app_name` BIGKAS-AI string, `max_audio_mb` 20 number, `independent_threshold` 97 number, `instructional_threshold` 90 number, `ml_enabled` true boolean, `auto_recommend` true boolean) PLUS the untouched seeder-only rows (`school_year`, `assessment_time_limit`, `min_words_for_assessment`, `enable_practice_center`, `enable_comprehension_questions`, `wpm_threshold_frustration` with their current values). Replace the loop with:

```php
SystemSetting::whereIn('setting_key', ['max_audio_size_mb', 'ml_service_enabled', 'default_language'])->delete();

foreach ($settings as $setting) {
    SystemSetting::firstOrCreate(['setting_key' => $setting['setting_key']], $setting);
}
```

- [ ] **Step 3: Run tests to verify they pass**

Run: `php artisan test --filter=SettingsTest`
Expected: 6 PASS.

- [ ] **Step 4: Converge the dev DB + run FULL suite**

Run: `php artisan db:seed --class=SystemSettingSeeder`
Expected: succeeds; dev `system_settings` holds canonical keys, no stale keys, no duplicates.
Run: `php artisan test`
Expected: all green, 0 failures. Fix any regression before continuing — do not bundle fixes.

- [ ] **Step 5: Commit (stage feature paths + plan file ONLY — never `git add -A`)**

```bash
git status --short
git add database/seeders/SystemSettingSeeder.php tests/Feature/SettingsTest.php docs/superpowers/plans/2026-09-29-settings-fix.md
git commit -m "chore(settings): align seeder keys and verify full suite"
```
