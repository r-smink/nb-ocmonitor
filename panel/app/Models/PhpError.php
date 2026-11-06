<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhpError extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Open = nooit als opgelost gemarkeerd, of daarna opnieuw opgetreden. */
    public function isOpen(): bool
    {
        return $this->resolved_at === null || $this->last_seen_at->gt($this->resolved_at);
    }
}
