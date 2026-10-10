<?php

namespace App\Services\Settings;

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;

class Settings
{
    private const CACHE_KEY = 'settings.all';

    public function __construct(private Cache $cache) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            return $default;
        }

        return $all[$key];
    }

    public function set(string $key, mixed $value, SettingType $type = SettingType::String): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $this->encode($value, $type), 'type' => $type],
        );
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->cache->remember(self::CACHE_KEY, now()->addMinutes(15), function (): array {
            $out = [];

            foreach (Setting::query()->get() as $setting) {
                $out[$setting->key] = $this->decode($setting->value, $setting->type);
            }

            return $out;
        });
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    private function encode(mixed $value, SettingType $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            SettingType::Boolean => $value ? '1' : '0',
            SettingType::Json => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    private function decode(?string $value, SettingType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            SettingType::Integer => (int) $value,
            SettingType::Boolean => $value === '1',
            SettingType::Json => json_decode($value, true),
            default => $value,
        };
    }
}
