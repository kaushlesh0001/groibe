<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's channel choices for one notification category (spec 14). */
class NotificationPreference extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'category', 'database', 'mail'];

    protected function casts(): array
    {
        return [
            'database' => 'boolean',
            'mail' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
