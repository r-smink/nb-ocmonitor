<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    public const PREFIX = 'ocm_';

    protected $fillable = ['name', 'token_hash', 'prefix', 'created_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** Maakt een token aan; de platte tekst wordt alleen hier teruggegeven en nergens bewaard. @return array{0: self, 1: string} */
    public static function issue(string $name, ?User $by, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX . Str::random(40);
        $token = self::query()->create([
            'name' => $name, 'token_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 10),
            'created_by' => $by?->id, 'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        if (!str_starts_with($plain, self::PREFIX) || strlen($plain) > 100) {
            return null;
        }
        $token = self::query()->where('token_hash', hash('sha256', $plain))->first();

        return $token !== null && $token->isActive() ? $token : null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
