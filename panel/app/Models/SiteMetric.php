<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Meetpunt per site per dag (opslag en aantal plugins) voor de trendgrafieken. */
class SiteMetric extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'day', 'db_bytes', 'content_bytes', 'uploads_bytes', 'disk_free_bytes', 'storage_partial', 'plugins', 'plugins_active', 'themes'];

    protected function casts(): array
    {
        // day blijft een tekst (Y-m-d): met een date-cast wordt het bij SQLite "Y-m-d 00:00:00" en vindt updateOrCreate de dag niet terug.
        return ['storage_partial' => 'boolean'];
    }
}
