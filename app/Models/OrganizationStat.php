<?php

namespace App\Models;

use Database\Factories\OrganizationStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cached review averages for one client organization. Admin-only in v1 (spec 9, SCR-020). */
class OrganizationStat extends Model
{
    /** @use HasFactory<OrganizationStatFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'projects_completed',
        'reviews_count',
        'avg_rating',
        'avg_clarity',
        'avg_communication',
        'avg_professionalism',
        'avg_feedback_time',
        'avg_fairness',
        'recomputed_at',
    ];

    protected function casts(): array
    {
        return [
            'avg_rating' => 'decimal:2',
            'avg_clarity' => 'decimal:2',
            'avg_communication' => 'decimal:2',
            'avg_professionalism' => 'decimal:2',
            'avg_feedback_time' => 'decimal:2',
            'avg_fairness' => 'decimal:2',
            'recomputed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
