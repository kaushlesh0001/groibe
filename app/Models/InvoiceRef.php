<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceRefFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A pointer to an invoice that lives in Zoho Books (spec 7.6). Groibe stores the reference and the status only. Clients never see it. */
class InvoiceRef extends Model
{
    /** @use HasFactory<InvoiceRefFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'zoho_invoice_ref',
        'amount',
        'currency',
        'status',
        'issued_at',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'issued_at' => 'date',
            'due_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'zoho_invoice_ref', 'amount', 'status', 'issued_at', 'due_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
