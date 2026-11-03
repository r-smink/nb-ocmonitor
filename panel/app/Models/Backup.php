<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    protected $fillable = ['backup_id', 'site_id', 'status', 'components', 'job_id', 'update_job_ids', 'db_size', 'files_size', 'db_sha256', 'files_sha256',
        'tables', 'woocommerce', 'error', 'restore_job_id', 'expires_at', 'restored_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'update_job_ids' => 'array',
            'woocommerce' => 'boolean',
            'expires_at' => 'datetime',
            'restored_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(SiteJob::class);
    }

    public function path(string $part): string
    {
        return 'backups/' . $this->site->site_id . '/' . $this->backup_id . '/' . $part . '.enc';
    }

    public function size(): int
    {
        return (int) $this->db_size + (int) $this->files_size;
    }

    public function describe(): string
    {
        $c = $this->components ?? [];
        if (!empty($c['full'])) {
            return 'volledige site (database en alle bestanden)';
        }
        $parts = array_filter([
            'database',
            !empty($c['plugins']) ? count($c['plugins']) . ' plugin' . (count($c['plugins']) === 1 ? '' : 's') : null,
            !empty($c['themes']) ? count($c['themes']) . ' thema' . (count($c['themes']) === 1 ? '' : '\'s') : null,
            !empty($c['core']) ? 'WordPress-bestanden' : null,
        ]);

        return implode(', ', $parts);
    }
}
