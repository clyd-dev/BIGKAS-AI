# Admin Settings Save/Load Fix — Design Spec

Date: 2026-09-29
Status: approved by user (4/4 sections; default_language removed)
Approach: A — fix the save/load contract, keep table design and typed API

## 1. Objective

Make Admin → System Settings functionally save and reload. The page is
currently decorative: saves collapse into one `settings` JSON blob,
loads hand Model objects to the view, seeder keys don't match view keys,
checkboxes can't turn off, and `default_language` is dropped entirely.
No new settings; no consumer wiring (nothing calls `getValue` yet —
driving assessment logic from these values is an explicit follow-up).

## 2. Key contract (6 settings)

| Key | Type | Default | Rule |
|---|---|---|---|
| `app_name` | string | `BIGKAS-AI` | max:100 |
| `max_audio_mb` | number | `20` | integer 1–100 |
| `independent_threshold` | number | `97` | integer 90–100 |
| `instructional_threshold` | number | `90` | integer 80–100, `< settings.independent_threshold` |
| `ml_enabled` | boolean | true | unchecked stores `'0'` |
| `auto_recommend` | boolean | true | unchecked stores `'0'` |

`default_language` is removed from view, controller whitelist, and seeder.
Seeder-only keys (`school_year`, `wpm_threshold_frustration`, etc.) stay.

## 3. Controller (`AdminController`)

- `settings()` builds a plain values array via `SystemSetting::getValue`
  + Section 2 defaults. The view never receives a Model again.
- `saveSettings()` validates the nested `settings.*` rules above;
  non-whitelisted keys are ignored (no junk rows); each key saved with
  its explicit type; missing checkboxes stored as `'0'`.
- Validation failures return with errors; success flash and changed-keys
  activity log unchanged.

## 4. View + seeder

- View keeps `settings[...]` names and layout; values are plain strings;
  checkboxes use ternary `checked`; `@error` hints under thresholds.
- Seeder: rename `max_audio_size_mb` → `max_audio_mb`,
  `ml_service_enabled` → `ml_enabled`; drop `default_language` row; add
  `independent_threshold` (97), `instructional_threshold` (90),
  `auto_recommend` (true); switch to `updateOrCreate` on `setting_key`;
  explicitly delete stale rows (`max_audio_size_mb`, `ml_service_enabled`,
  `default_language`).
  Re-seeding converges dev DBs (old key names removed, new keys created).

## 5. Testing

New `SettingsTest`: full save → per-key DB values and types; reload
shows saved values; unchecked boxes persist `'0'`; invalid thresholds
rejected with nothing saved; junk key ignored. Full suite stays green.
