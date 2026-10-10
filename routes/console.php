<?php

use Illuminate\Support\Facades\Schedule;

// Shared hosting: no persistent workers. Cron runs `schedule:run` every minute (spec 17.1).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Overdue task digests for delivery managers, once a day (Pass 4).
Schedule::command('groibe:notify-overdue-tasks')
    ->dailyAt('08:00')
    ->withoutOverlapping(30);
