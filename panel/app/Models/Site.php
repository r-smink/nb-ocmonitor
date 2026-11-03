<?php

namespace App\Models;

use App\Ocm\SiteIssues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NextBuzz\OcMonitor\Protocol\Base64Url;

class Site extends Model
{
    /** Gelijk aan Scopes::DEFAULT in de plugin; site.info geeft de werkelijke scopes terug. */
    public const DEFAULT_SCOPES = ['read', 'events', 'updates', 'users', 'maintenance', 'licenses'];

    protected $fillable = ['site_id', 'name', 'client_name', 'customer_id', 'url', 'host'];

    protected $hidden = ['pairing_secret', 'inventory', 'wp_users'];

    /** @var list<array{level: string, category: string, text: string}>|null */
    private ?array $issuesCache = null;

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
            'pagespeed_requested_at' => 'datetime',
            'down_since' => 'datetime',
            'uptime_checked_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
            'domain_expires_at' => 'datetime',
            'domain_checked_at' => 'datetime',
            'wp_users' => 'array',
            'wp_users_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'site_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /** Eigen tags plus die van de klant. @return \Illuminate\Support\Collection<int, Tag> */
    public function allTags(): \Illuminate\Support\Collection
    {
        return $this->tags->concat($this->customer?->tags ?? collect())->unique('id')->sortBy('name')->values();
    }

    /** Database opschonen vanuit het panel: plugin 0.6.0+ en scope maintenance. */
    public function cleanupBlockedReason(): ?string
    {
        if (!$this->isPaired()) {
            return 'Site is niet gekoppeld';
        }
        if ($this->plugin_version === null || version_compare($this->plugin_version, '0.6.0', '<')) {
            return 'Vanaf client-plugin 0.6.0 (deze site: ' . ($this->plugin_version ?? '?') . ')';
        }

        return $this->hasScope('maintenance') ? null : 'Opschonen staat niet aan op de site';
    }

    public function events(): HasMany
    {
        return $this->hasMany(SiteEvent::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(SiteJob::class);
    }

    public function visualChecks(): HasMany
    {
        return $this->hasMany(VisualCheck::class);
    }

    /** Gebruikersbeheer vanuit het panel: plugin 0.4.0+ en scope users. */
    public function usersBlockedReason(): ?string
    {
        if (!$this->isPaired()) {
            return 'Site is niet gekoppeld';
        }
        if ($this->plugin_version === null || version_compare($this->plugin_version, '0.4.0', '<')) {
            return 'Client-plugin ' . ($this->plugin_version ?? '?') . ' kan nog geen gebruikers beheren (minimaal 0.4.0)';
        }

        return $this->hasScope('users') ? null : 'Gebruikersbeheer staat niet aan op de site';
    }

    public function pagespeedResults(): HasMany
    {
        return $this->hasMany(PagespeedResult::class);
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

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** Waarom het panel hier geen updates kan starten, of null als het wel kan. */
    public function updatesBlockedReason(): ?string
    {
        if (!$this->isPaired()) {
            return 'Site is niet gekoppeld';
        }
        $min = (string) config('ocm.updates.min_plugin_version');
        if ($this->plugin_version !== null && version_compare($this->plugin_version, $min, '<')) {
            return "Client-plugin {$this->plugin_version} is te oud voor updates vanuit het panel (minimaal {$min})";
        }
        if (!$this->hasScope('updates')) {
            return 'Updates vanuit het panel staan uit op de site (WP-admin → Extra → NB OC Monitor)';
        }

        return null;
    }

    /**
     * Beschikbare updates volgens de laatste inventaris.
     *
     * @return list<array{type: string, id: string, slug: string, name: string, from: string, to: string, active: bool}>
     */
    public function availableUpdates(): array
    {
        $inventory = $this->inventory ?? [];
        $items = [];
        if (!empty($inventory['core']['update']['version'])) {
            $items[] = ['type' => 'core', 'id' => 'wordpress', 'slug' => 'wordpress', 'name' => 'WordPress',
                'from' => (string) ($inventory['core']['version'] ?? ''), 'to' => (string) $inventory['core']['update']['version'], 'active' => true];
        }
        foreach ((array) ($inventory['plugins'] ?? []) as $plugin) {
            if (is_array($plugin) && isset($plugin['file']) && !empty($plugin['update']['version'])) {
                $items[] = ['type' => 'plugin', 'id' => (string) $plugin['file'], 'slug' => (string) ($plugin['slug'] ?? $plugin['file']),
                    'name' => (string) ($plugin['name'] ?? $plugin['file']), 'from' => (string) ($plugin['version'] ?? ''),
                    'to' => (string) $plugin['update']['version'], 'active' => !empty($plugin['active'])];
            }
        }
        foreach ((array) ($inventory['themes'] ?? []) as $theme) {
            if (is_array($theme) && isset($theme['stylesheet']) && !empty($theme['update']['version'])) {
                $items[] = ['type' => 'theme', 'id' => (string) $theme['stylesheet'], 'slug' => (string) $theme['stylesheet'],
                    'name' => (string) ($theme['name'] ?? $theme['stylesheet']), 'from' => (string) ($theme['version'] ?? ''),
                    'to' => (string) $theme['update']['version'], 'active' => !empty($theme['active'])];
            }
        }
        if ($release = \App\Ocm\PluginRelease::offerFor($this->plugin_version)) {
            $items[] = ['type' => 'client', 'id' => 'nb-ocmonitor-client', 'slug' => 'nb-ocmonitor-client', 'name' => 'NB OC Monitor Client',
                'from' => (string) $this->plugin_version, 'to' => $release['version'], 'active' => true];
        }

        return $items;
    }

    /**
     * @param list<array{level: string, category: string, text: string}> $issues
     */
    public function setIssues(array $issues): void
    {
        $this->issuesCache = $issues;
    }

    /** @return list<array{level: string, category: string, text: string}> */
    public function issues(): array
    {
        if ($this->issuesCache === null) {
            SiteIssues::load(collect([$this]));
        }

        return $this->issuesCache;
    }

    /** Niveau voor de tegelweergave (ServerDash-klassen lvl-ok/warn/crit/pending). */
    public function level(): string
    {
        return SiteIssues::levelOf($this, $this->issues());
    }
}
