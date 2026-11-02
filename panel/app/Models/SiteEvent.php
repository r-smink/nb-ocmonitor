<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'data' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function sourceLabel(): string
    {
        return [
            'nb-ocm' => 'Panel',
            'wp-admin' => 'WP-admin',
            'wp-cli' => 'WP-CLI',
            'wp-login' => 'Login',
            'auto-update' => 'Auto-update',
            'cron' => 'Cron',
            'rest' => 'REST',
            'unknown' => 'Extern / onbekend',
        ][$this->source] ?? $this->source;
    }
}
