<?php

namespace App\Models;

use App\Models\Concerns\GatedByReview;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A CMS page served at /{slug} (spec 7.9, 10.2). Only active pages are public. */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use GatedByReview, HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['slug', 'title', 'body_html', 'meta_title', 'meta_description', 'is_active', 'needs_review'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'title', 'meta_title', 'meta_description', 'is_active', 'needs_review'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
