<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exceptions\IllegalTransition;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** One uploaded CSV and what became of it (spec 7.9, 15.1, SCR-027). Holds paths and counts, never the rows themselves. */
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'original_name',
        'file_path',
        'status',
        'mapping',
        'dry_run',
        'total_rows',
        'success_rows',
        'failed_rows',
        'skipped_rows',
        'error_report_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'mapping' => 'array',
            'dry_run' => 'boolean',
            'total_rows' => 'integer',
            'success_rows' => 'integer',
            'failed_rows' => 'integer',
            'skipped_rows' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'status', 'dry_run', 'total_rows', 'success_rows', 'failed_rows', 'skipped_rows'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** False once the housekeeping job has removed the uploaded CSV (SCR-034); the row, counts and status stay. */
    public function hasStoredFile(): bool
    {
        return Storage::disk('local')->exists($this->file_path);
    }

    /** Moves to a new status, or throws when the state machine does not allow it. */
    public function moveTo(ImportStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw IllegalTransition::for('import', $this->status->value, $to->value);
        }

        $this->status = $to;
        $this->save();
    }
}
