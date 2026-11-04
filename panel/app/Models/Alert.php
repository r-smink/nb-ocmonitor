<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Een incident: een aandachtspunt van een site, van het moment dat het opduikt tot het is opgelost. */
class Alert extends Model
{
    protected $fillable = ['site_id', 'key', 'level', 'category', 'text', 'opened_at', 'last_seen_at', 'escalated_at', 'resolved_at', 'notify'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notify' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function duration(): string
    {
        return $this->opened_at->diffForHumans($this->resolved_at ?? now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2);
    }
}
