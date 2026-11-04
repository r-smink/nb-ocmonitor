<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UptimeIncident extends Model
{
    protected $fillable = ['site_id', 'started_at', 'ended_at', 'cause'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Duur in minuten, of tot nu als de storing nog loopt. */
    public function minutes(): int
    {
        return (int) max(1, round($this->started_at->diffInSeconds($this->ended_at ?? now()) / 60));
    }

    public function durationLabel(): string
    {
        $m = $this->minutes();

        return $m < 60 ? "{$m} min" : intdiv($m, 60) . ' u ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . ' min';
    }
}
