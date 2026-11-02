<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NextBuzz\OcMonitor\Protocol\Base64Url;

class Site extends Model
{
    public const DEFAULT_SCOPES = ['read', 'events'];

    protected $fillable = ['site_id', 'name', 'client_name', 'url', 'host'];

    protected $hidden = ['pairing_secret', 'inventory'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'pairing_secret' => 'encrypted',
            'pairing_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'inventory' => 'array',
            'inventory_at' => 'datetime',
            'event_gap_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'site_id';
    }

    public function events(): HasMany
    {
        return $this->hasMany(SiteEvent::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(SiteJob::class);
    }

    public function isPaired(): bool
    {
        return $this->public_key !== null;
    }

    public function publicKeyRaw(): ?string
    {
        return $this->public_key !== null ? Base64Url::decode($this->public_key) : null;
    }

    public function pairingIsOpen(): bool
    {
        return !$this->isPaired() && $this->pairing_secret !== null && $this->pairing_expires_at?->isFuture();
    }

    /** Niveau voor de tegelweergave (ServerDash-klassen lvl-ok/warn/crit/pending). */
    public function level(): string
    {
        if (!$this->isPaired()) {
            return 'pending';
        }
        if ($this->status === 'unreachable' || $this->status === 'error') {
            return 'crit';
        }
        $heartbeat = config('ocm.heartbeat_minutes') * 3;
        if ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subMinutes($heartbeat)) || $this->event_gap_at !== null) {
            return 'warn';
        }

        return $this->updates_count > 0 ? 'warn' : 'ok';
    }

    /** @return list<array{level: string, text: string}> */
    public function issues(): array
    {
        $issues = [];
        if (!$this->isPaired()) {
            $issues[] = ['level' => 'info', 'text' => $this->pairingIsOpen() ? 'Wacht op koppeling' : 'Koppelcode verlopen'];
            return $issues;
        }
        if ($this->status === 'unreachable') {
            $issues[] = ['level' => 'crit', 'text' => 'Niet bereikbaar'];
        } elseif ($this->status === 'error') {
            $issues[] = ['level' => 'crit', 'text' => 'Fout bij laatste contact'];
        }
        $heartbeat = config('ocm.heartbeat_minutes') * 3;
        if ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subMinutes($heartbeat))) {
            $issues[] = ['level' => 'warn', 'text' => 'Geen heartbeat sinds ' . ($this->last_seen_at?->diffForHumans() ?? 'koppeling')];
        }
        if ($this->event_gap_at !== null) {
            $issues[] = ['level' => 'warn', 'text' => 'Gat in wijzigingslog'];
        }
        if ($this->updates_count > 0) {
            $issues[] = ['level' => 'warn', 'text' => $this->updates_count . ' update(s) beschikbaar'];
        }

        return $issues;
    }
}
