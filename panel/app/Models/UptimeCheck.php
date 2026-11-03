<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UptimeCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'checked_at', 'ok', 'status', 'response_ms', 'error'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'ok' => 'boolean'];
    }
}
