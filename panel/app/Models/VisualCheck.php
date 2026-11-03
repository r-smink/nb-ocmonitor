<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Screenshot van de homepage vóór en na een reeks updates, met het percentage zichtbare verandering.
 * Status: before (wacht op de eerste screenshot), updating (updates lopen), done, skipped.
 */
class VisualCheck extends Model
{
    protected $fillable = ['site_id', 'status', 'job_ids', 'before_image', 'after_image', 'diff_image', 'diff_percent', 'error', 'before_at', 'after_at', 'reviewed_at', 'reviewed_by'];

    protected $hidden = ['before_image', 'after_image', 'diff_image'];

    protected function casts(): array
    {
        return [
            'job_ids' => 'array',
            'diff_percent' => 'float',
            'before_at' => 'datetime',
            'after_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function changed(): bool
    {
        return $this->status === 'done' && $this->diff_percent !== null && $this->diff_percent >= (float) config('ocm.visual.threshold_percent');
    }
}
