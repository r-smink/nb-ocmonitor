<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Label voor sites en klanten, bijv. "webshop" of "hosting bij ons". Een site heeft zijn eigen
 * tags plus die van zijn klant.
 */
class Tag extends Model
{
    public const COLORS = ['blue' => 'Blauw', 'green' => 'Groen', 'orange' => 'Oranje', 'red' => 'Rood', 'purple' => 'Paars', 'gray' => 'Grijs'];

    protected $fillable = ['name', 'color'];

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class);
    }

    /**
     * Sites met deze tag, direct of via hun klant.
     *
     * @param Collection<int, Site> $sites (met tags en customer.tags geladen)
     * @return Collection<int, Site>
     */
    public static function filter(Collection $sites, ?int $tagId): Collection
    {
        if (!$tagId) {
            return $sites;
        }

        return $sites->filter(fn (Site $s) => $s->allTags()->contains('id', $tagId))->values();
    }
}
