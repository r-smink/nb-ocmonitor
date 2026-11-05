<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokenLink extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['found_on' => 'array', 'internal' => 'boolean', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'ignored_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function label(): string
    {
        return $this->status_code ? 'HTTP ' . $this->status_code : ($this->error ? 'Niet bereikbaar' : 'Fout');
    }
}
