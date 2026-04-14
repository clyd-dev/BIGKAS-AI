<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'setting_key',
        'setting_value',
        'setting_type',
        'description',
    ];

    /**
     * Get a setting value by key.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = self::where('setting_key', $key)->first();

        if (!$setting) {
            return $default;
        }

        return match ($setting->setting_type) {
            'number' => (float) $setting->setting_value,
            'boolean' => filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($setting->setting_value, true),
            default => $setting->setting_value,
        };
    }

    /**
     * Set a setting value.
     */
    public static function setValue(string $key, mixed $value, ?string $type = null): void
    {
        $setting = self::firstOrNew(['setting_key' => $key]);

        if ($type) {
            $setting->setting_type = $type;
        }

        $setting->setting_value = is_array($value) ? json_encode($value) : (string) $value;
        $setting->updated_at = now();
        $setting->save();
    }
}
