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

    /** Leesbare naam per eventtype (overzicht en sitepagina). */
    public const LABELS = [
        'plugin.update' => 'Plugin bijgewerkt', 'theme.update' => 'Thema bijgewerkt', 'core.update' => 'WordPress bijgewerkt', 'core.updated' => 'WordPress bijgewerkt',
        'plugin.install' => 'Plugin geïnstalleerd', 'plugin.activate' => 'Plugin geactiveerd', 'plugin.activated' => 'Plugin geactiveerd',
        'plugin.deactivate' => 'Plugin gedeactiveerd', 'plugin.deactivated' => 'Plugin gedeactiveerd', 'plugin.delete' => 'Plugin verwijderd', 'plugin.deleted' => 'Plugin verwijderd',
        'theme.switch' => 'Ander thema', 'theme.switched' => 'Ander thema', 'theme.install' => 'Thema geïnstalleerd', 'theme.deleted' => 'Thema verwijderd',
        'user.created' => 'Gebruiker aangemaakt', 'user.deleted' => 'Gebruiker verwijderd', 'user.role_changed' => 'Rol gewijzigd', 'option.changed' => 'Instelling gewijzigd',
        'plugin.rolled_back' => 'Plugin teruggedraaid', 'theme.rolled_back' => 'Thema teruggedraaid', 'translation.updated' => 'Vertaling bijgewerkt',
        'component.changed_externally' => 'Gewijzigd buiten WordPress om', 'login.failed_summary' => 'Mislukte inlogpogingen', 'db.cleaned' => 'Database opgeschoond',
        'site.maintenance' => 'Onderhoudsmodus',
    ];

    /** Eén regel, bijv. "Plugin bijgewerkt: elementor 3.21 → 3.23". */
    public function summary(): string
    {
        $object = $this->object ? ': ' . \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before($this->object, '/') ?: $this->object, 40) : '';
        $versions = $this->from_version && $this->to_version ? ' ' . $this->from_version . ' → ' . $this->to_version : '';

        return (self::LABELS[$this->type] ?? $this->type) . $object . $versions . ($this->result === 'failed' ? ' (mislukt)' : '');
    }

    /** ok, warn (buiten het panel/onbekend) of crit (mislukt), voor de status-stip. */
    public function dotLevel(): string
    {
        return $this->result === 'failed' ? 'crit' : ($this->source === 'unknown' ? 'warn' : 'ok');
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
