<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The size of the database against the host plan's limit (spec 17.4, SCR-034). The daily check measures and records it;
 * the admin System status widget shows the last record, so the page never runs the measuring query itself.
 */
class DatabaseSize
{
    public const KEY = 'groibe.db.size';

    /** Size in MB, from the database's own catalogue. MySQL and MariaDB count data plus indexes; SQLite counts its pages. */
    public function megabytes(): float
    {
        $connection = DB::connection();

        $bytes = $connection->getDriverName() === 'sqlite'
            ? (int) ($connection->selectOne('PRAGMA page_count')->page_count ?? 0) * (int) ($connection->selectOne('PRAGMA page_size')->page_size ?? 0)
            : (int) ($connection->selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()')->bytes ?? 0);

        return round($bytes / 1048576, 2);
    }

    public function limitMb(): int
    {
        return max(1, (int) config('groibe.ops.db_size_limit_mb'));
    }

    public function warnPercent(): int
    {
        return max(1, (int) config('groibe.ops.db_size_warn_percent'));
    }

    public function percentOf(float $megabytes): int
    {
        return (int) floor($megabytes / $this->limitMb() * 100);
    }

    public function isOverWarningLevel(float $megabytes): bool
    {
        return $this->percentOf($megabytes) >= $this->warnPercent();
    }

    /** Keeps the latest measurement for the widget. */
    public function record(float $megabytes): void
    {
        Cache::forever(self::KEY, ['mb' => $megabytes, 'checked_at' => now()->getTimestamp()]);
    }

    /** @return array{mb: float, limit_mb: int, percent: int, warn: bool, checked_at: CarbonImmutable}|null */
    public function lastCheck(): ?array
    {
        $record = Cache::get(self::KEY);

        if (! is_array($record) || ! isset($record['mb'], $record['checked_at'])) {
            return null;
        }

        $mb = (float) $record['mb'];

        return [
            'mb' => $mb,
            'limit_mb' => $this->limitMb(),
            'percent' => $this->percentOf($mb),
            'warn' => $this->isOverWarningLevel($mb),
            'checked_at' => CarbonImmutable::createFromTimestamp((int) $record['checked_at']),
        ];
    }
}
