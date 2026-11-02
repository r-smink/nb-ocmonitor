<?php

namespace App\Ocm;

use App\Models\ActivityLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit log met hash-keten (plan §2.4): elke regel bevat de hash van de vorige, zodat
 * achteraf verwijderen of wijzigen zichtbaar wordt met `php artisan ocm:verify-log`.
 */
final class Activity
{
    /**
     * @param array<string, mixed> $details
     */
    public static function log(string $action, array $details = [], ?Site $site = null, ?User $user = null, ?string $ip = null): void
    {
        $user ??= auth()->user();
        $ip ??= app()->runningInConsole() ? null : request()->ip();

        DB::transaction(function () use ($action, $details, $site, $user, $ip): void {
            $prev = ActivityLog::query()->orderByDesc('id')->lockForUpdate()->value('hash');
            $createdAt = now()->utc()->format('Y-m-d H:i:s');
            $entry = [
                'user_id' => $user?->id,
                'site_id' => $site?->id,
                'action' => $action,
                'details' => $details === [] ? null : $details,
                'ip' => $ip,
                'created_at' => $createdAt,
            ];

            ActivityLog::query()->create($entry + [
                'prev_hash' => $prev,
                'hash' => self::hash($prev, $entry),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function hash(?string $prevHash, array $entry): string
    {
        $line = json_encode([
            $entry['user_id'],
            $entry['site_id'],
            $entry['action'],
            $entry['details'],
            $entry['ip'],
            $entry['created_at'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', ($prevHash ?? '') . "\n" . $line);
    }

    /**
     * @return array{ok: bool, checked: int, broken_at: ?int}
     */
    public static function verifyChain(): array
    {
        $prev = null;
        $checked = 0;
        foreach (ActivityLog::query()->orderBy('id')->cursor() as $row) {
            $entry = [
                'user_id' => $row->user_id,
                'site_id' => $row->site_id,
                'action' => $row->action,
                'details' => $row->details,
                'ip' => $row->ip,
                'created_at' => $row->getRawOriginal('created_at'),
            ];
            if ($row->prev_hash !== $prev || !hash_equals($row->hash, self::hash($prev, $entry))) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $row->id];
            }
            $prev = $row->hash;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
