<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** Een betaalde plugin of een betaald thema waarvoor jullie licenties hebben. */
class LicenseProduct extends Model
{
    public const METHODS = [
        'elementor_pro' => 'Automatisch: Elementor Pro',
        'acf_pro' => 'Automatisch: ACF Pro',
        'option' => 'Automatisch: sleutel in een optie zetten',
        'manual' => 'Handmatig in WP-admin',
    ];

    /** Bekende producten; bij toevoegen vult het panel deze gegevens in. */
    public const PRESETS = [
        'elementor_pro' => ['name' => 'Elementor Pro', 'plugin_file' => 'elementor-pro/elementor-pro.php', 'method' => 'elementor_pro', 'status_key' => 'elementor_pro', 'settings_path' => 'admin.php?page=elementor-license'],
        'acf_pro' => ['name' => 'ACF Pro', 'plugin_file' => 'advanced-custom-fields-pro/acf.php', 'method' => 'acf_pro', 'status_key' => 'acf_pro', 'settings_path' => 'edit.php?post_type=acf-field-group&page=acf-settings-updates'],
        'formidable_pro' => ['name' => 'Formidable Pro', 'plugin_file' => 'formidable-pro/formidable-pro.php', 'method' => 'manual', 'status_key' => null, 'settings_path' => 'admin.php?page=formidable-settings'],
        'rank_math_pro' => ['name' => 'Rank Math Pro', 'plugin_file' => 'seo-by-rank-math-pro/rank-math-pro.php', 'method' => 'manual', 'status_key' => 'rank_math_pro', 'settings_path' => 'admin.php?page=rank-math&view=help'],
    ];

    protected $fillable = ['name', 'plugin_file', 'method', 'option_name', 'status_key', 'settings_path', 'notes'];

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class)->orderBy('label');
    }

    public function automatic(): bool
    {
        return $this->method !== 'manual';
    }

    /**
     * Sites waarop deze plugin staat, met de licentiestatus die de site meldt.
     *
     * @param Collection<int, Site> $sites
     * @return Collection<int, array{site: Site, version: ?string, active: bool, status: ?bool}>
     */
    public function installations(Collection $sites): Collection
    {
        return $sites->map(function (Site $site) {
            $plugin = collect((array) (($site->inventory ?? [])['plugins'] ?? []))->firstWhere('file', $this->plugin_file);
            if ($plugin === null) {
                return null;
            }
            $status = $this->status_key ? (($site->inventory['licenses'][$this->status_key]['active'] ?? null)) : null;

            return ['site' => $site, 'version' => $plugin['version'] ?? null, 'active' => !empty($plugin['active']), 'status' => is_bool($status) ? $status : null];
        })->filter()->values();
    }
}
