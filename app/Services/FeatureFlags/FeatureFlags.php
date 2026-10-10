<?php

namespace App\Services\FeatureFlags;

use App\Enums\FeatureFlag as Flag;
use App\Models\FeatureFlag as FlagModel;
use Illuminate\Contracts\Cache\Repository as Cache;

class FeatureFlags
{
    private const CACHE_KEY = 'feature_flags.all';

    public function __construct(private Cache $cache) {}

    public function enabled(Flag|string $flag): bool
    {
        $key = $flag instanceof Flag ? $flag->value : $flag;
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return Flag::tryFrom($key)?->default() ?? false;
    }

    public function disabled(Flag|string $flag): bool
    {
        return ! $this->enabled($flag);
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return $this->cache->remember(self::CACHE_KEY, now()->addMinutes(15), function (): array {
            /** @var array<string, bool> $flags */
            $flags = FlagModel::query()->pluck('enabled', 'key')->map(fn ($v) => (bool) $v)->all();

            return $flags;
        });
    }

    public function set(Flag|string $flag, bool $enabled): void
    {
        $key = $flag instanceof Flag ? $flag->value : $flag;

        FlagModel::query()->updateOrCreate(['key' => $key], ['enabled' => $enabled]);
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
