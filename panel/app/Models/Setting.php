<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Eenvoudige key/value-instellingen (JSON), bijv. de rapport-opmaak. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, array $default = []): array
    {
        $value = self::query()->find($key)?->value;

        return is_array($value) ? $value : $default;
    }

    public static function put(string $key, array $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
