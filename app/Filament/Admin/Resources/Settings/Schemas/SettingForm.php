<?php

namespace App\Filament\Admin\Resources\Settings\Schemas;

use App\Enums\SettingType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SettingForm
{
    /** Shown under Value (SCR-039 amendment). Built from config, so the text cannot drift from the real minimums and defaults. */
    public static function retentionNote(): string
    {
        $min = (array) config('groibe.ops.retention_min');
        $default = (array) config('groibe.ops.retention');

        return sprintf(
            'For a retention setting, a whole number of days. A value below the minimum is ignored and the default is used: failed jobs and job batches at least %d (default %d), read notifications at least %d (default %d), audit log at least %d (default %d). A value that is not a whole number is ignored too.',
            $min['failed_jobs_days'], $default['failed_jobs_days'], $min['notifications_read_days'], $default['notifications_read_days'], $min['activity_log_days'], $default['activity_log_days'],
        );
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->helperText('How long data is kept: retention.failed_jobs_days, retention.job_batches_days, retention.notifications_read_days or retention.activity_log_days. Use type Integer.'),
                Textarea::make('value')
                    ->helperText(fn (): string => self::retentionNote())
                    ->columnSpanFull(),
                Select::make('type')
                    ->options(SettingType::class)
                    ->default('string')
                    ->required(),
            ]);
    }
}
