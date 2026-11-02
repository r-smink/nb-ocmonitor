<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagespeedResult extends Model
{
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    public const STRATEGIES = ['mobile' => 'Mobiel', 'desktop' => 'Desktop'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'field' => 'array',
            'opportunities' => 'array',
            'cls' => 'float',
            'measured_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Laatste geslaagde meting per site en strategie: [site_id => [strategy => result]].
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<string, self>>
     */
    public static function latestPerSite(): \Illuminate\Support\Collection
    {
        $ids = self::query()->selectRaw('max(id)')->whereNull('error')->groupBy('site_id', 'strategy');

        return self::query()->whereIn('id', $ids)->get()->groupBy('site_id')->map(fn ($rows) => $rows->keyBy('strategy'));
    }

    /** ServerDash-niveau voor een Lighthouse-score (zelfde grenzen als Google: 90 / 50). */
    public static function level(?int $score): string
    {
        return match (true) {
            $score === null => 'pending',
            $score >= 90 => 'ok',
            $score >= 50 => 'warn',
            default => 'crit',
        };
    }
}
