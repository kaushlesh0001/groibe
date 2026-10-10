<?php

namespace App\Models;

use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FeatureFlag extends Model
{
    use LogsActivity;

    /** @var list<string> */
    protected $fillable = ['key', 'enabled', 'notes'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        $bust = fn () => app(FeatureFlags::class)->flush();
        static::saved($bust);
        static::deleted($bust);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['key', 'enabled'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
