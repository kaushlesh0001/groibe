<?php

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email outcome (spec 7.8). Written by EmailLogger only, never edited. Holds recipient addresses, never the message body. */
class EmailLog extends Model
{
    protected $table = 'email_log';

    /** @var list<string> */
    protected $fillable = ['user_id', 'template', 'to', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
