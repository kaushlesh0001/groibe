<?php

namespace App\Models;

use App\Enums\SettingType;
use App\Services\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Setting extends Model
{
    use LogsActivity;

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'type'];

    protected function casts(): array
    {
        return ['type' => SettingType::class];
    }

    protected static function booted(): void
    {
        $bust = fn () => app(Settings::class)->flush();
        static::saved($bust);
        static::deleted($bust);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['key', 'value', 'type'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
