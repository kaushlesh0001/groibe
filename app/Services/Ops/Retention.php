<?php

namespace App\Services\Ops;

use App\Services\Settings\Settings;

/**
 * How many days each kind of data is kept (spec 17.4, SCR-039). A super admin can change a value in System, Settings under the
 * key `retention.<name>`; when the row is missing, or holds anything but a whole number of 1 or more, the default from
 * `config/groibe.php` is used. A whole number below the minimum (7 days for failed jobs and job batches, 30 for read notifications,
 * 90 for the audit log) is ignored the same way, so a typo can never switch pruning off or wipe the audit trail (SCR-039).
 */
class Retention
{
    public const NAMES = ['failed_jobs_days', 'job_batches_days', 'notifications_read_days', 'activity_log_days'];

    public function __construct(private Settings $settings) {}

    public function days(string $name): int
    {
        $default = (int) config("groibe.ops.retention.{$name}");
        $value = $this->settings->get("retention.{$name}");

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $this->minimum($name) ? $value : max($this->minimum($name), $default);
    }

    /** The lowest number of days a setting may hold; never below 1. */
    public function minimum(string $name): int
    {
        return max(1, (int) config("groibe.ops.retention_min.{$name}"));
    }
}
