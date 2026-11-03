<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class License extends Model
{
    protected $fillable = ['license_product_id', 'label', 'key', 'seats', 'expires_on', 'notes'];

    protected $hidden = ['key'];

    protected function casts(): array
    {
        return ['key' => 'encrypted', 'expires_on' => 'immutable_date', 'seats' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LicenseProduct::class, 'license_product_id');
    }

    public function masked(): string
    {
        $key = (string) $this->key;

        return strlen($key) <= 8 ? str_repeat('•', strlen($key)) : substr($key, 0, 4) . str_repeat('•', 8) . substr($key, -4);
    }
}
