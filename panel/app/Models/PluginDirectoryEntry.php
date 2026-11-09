<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginDirectoryEntry extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'plugin_directory';

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    protected $fillable = ['slug', 'listed', 'closed', 'closed_reason', 'closed_date', 'last_updated', 'checked_at', 'version', 'requires_php', 'requires_wp', 'tested'];

    protected function casts(): array
    {
        return ['listed' => 'boolean', 'closed' => 'boolean', 'closed_date' => 'date', 'last_updated' => 'datetime', 'checked_at' => 'datetime'];
    }
}
