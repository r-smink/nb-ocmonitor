<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Samenvatting en risico-inschatting van Claude voor één sprong van een plugin (van → naar). */
class UpdateInsight extends Model
{
    protected $fillable = ['type', 'slug', 'from_version', 'to_version', 'summary', 'risk', 'reason', 'model'];

    public static function key(string $type, string $slug, string $from, string $to): string
    {
        return $type . ':' . strtolower($slug) . '|' . $from . '|' . $to;
    }
}
