<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Changelog van een plugin op wordpress.org, per versie. */
class PluginChangelog extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    protected $fillable = ['slug', 'version', 'entries', 'fetched_at'];

    protected function casts(): array
    {
        return ['entries' => 'array', 'fetched_at' => 'datetime'];
    }
}
