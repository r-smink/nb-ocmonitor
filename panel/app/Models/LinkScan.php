<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkScan extends Model
{
    protected $fillable = ['site_id', 'status', 'pages', 'links', 'broken', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
