<?php

namespace App\Models;

use App\Enums\SowStatus;
use Database\Factories\SowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Statement of Work, versioned per project (spec 6.3, 7.3, 8). */
class Sow extends Model
{
    /** @use HasFactory<SowFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'sows';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'version',
        'status',
        'title',
        'body_html',
        'body_variables',
        'total_value',
        'currency',
        'payment_terms',
        'sent_at',
        'accepted_at',
        'accepted_by',
        'accepted_ip',
        'accepted_user_agent',
        'superseded_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SowStatus::class,
            'version' => 'integer',
            'body_variables' => 'array',
            'total_value' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'version', 'status', 'title', 'total_value', 'payment_terms', 'accepted_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** @return BelongsTo<Sow, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    /** The agreement template this SOW was rendered from, kept in body_variables metadata. */
    public function templateId(): ?int
    {
        $id = $this->body_variables['_template_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
