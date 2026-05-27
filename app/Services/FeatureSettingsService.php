<?php

namespace App\Services;

use App\Models\FeatureSetting;

class FeatureSettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = FeatureSetting::query()->where('key', $key)->first();
        if (!$setting) {
            return $default;
        }

        $value = $setting->value;

        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    public function set(string $key, mixed $value, ?int $actorId = null): FeatureSetting
    {
        return FeatureSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => ['value' => $value],
                'updated_by' => $actorId,
            ]
        );
    }

    public function integer(string $key, int $default): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
