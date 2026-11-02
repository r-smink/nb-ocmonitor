<?php

namespace App\Ocm;

use App\Models\Site;
use App\Models\SiteEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Verwerkt events van een site (gepusht of opgehaald). Houdt per site het hoogste aaneengesloten
 * volgnummer bij en detecteert gaten (plan §2.7).
 */
final class EventIngestor
{
    private const SOURCES = ['nb-ocm', 'wp-admin', 'wp-cli', 'wp-login', 'auto-update', 'cron', 'rest', 'unknown'];

    /**
     * @param array<mixed> $events
     * @param int|null $pulledAfter bij events.pull: de after_seq van het verzoek (voor gatdetectie)
     * @return array{inserted: int, needs_pull: bool}
     */
    public function ingest(Site $site, array $events, ?int $pulledAfter = null): array
    {
        $rows = [];
        foreach (array_slice($events, 0, 1000) as $event) {
            $row = $this->normalize($site, $event);
            if ($row !== null) {
                $rows[$row['seq']] = $row;
            }
        }
        ksort($rows);

        $inserted = $rows === [] ? 0 : DB::table('site_events')->insertOrIgnore(array_values($rows));

        return DB::transaction(function () use ($site, $rows, $pulledAfter, $inserted): array {
            $site = Site::query()->lockForUpdate()->findOrFail($site->id);
            $last = (int) $site->last_event_seq;

            // Bij een pull vanaf $pulledAfter: begint de reeks later dan verwacht, dan zijn er
            // events op de site verdwenen (tabel geleegd of plugin uitgeschakeld geweest).
            if ($pulledAfter !== null && $rows !== [] && $pulledAfter === $last && $last > 0) {
                $first = (int) array_key_first($rows);
                if ($first > $last + 1) {
                    $site->event_gap_at = now();
                    Activity::log('event_gap', ['missing_from' => $last + 1, 'missing_to' => $first - 1], $site);
                    $last = $first - 1;
                }
            }

            $following = SiteEvent::query()->where('site_id', $site->id)->where('seq', '>', $last)->orderBy('seq')->limit(5000)->pluck('seq');
            foreach ($following as $seq) {
                if ((int) $seq !== $last + 1) {
                    break;
                }
                $last = (int) $seq;
            }

            $site->last_event_seq = $last;
            $site->save();

            return [
                'inserted' => $inserted,
                'needs_pull' => SiteEvent::query()->where('site_id', $site->id)->where('seq', '>', $last + 1)->exists(),
            ];
        });
    }

    /**
     * @param mixed $event
     * @return array<string, mixed>|null
     */
    private function normalize(Site $site, $event): ?array
    {
        if (!is_array($event) || !is_int($event['seq'] ?? null) || $event['seq'] < 1 || !is_int($event['time'] ?? null)) {
            return null;
        }
        $type = (string) ($event['type'] ?? '');
        if (preg_match('/^[a-z_]+(\.[a-z_]+)*$/D', $type) !== 1) {
            return null;
        }
        $source = (string) ($event['source'] ?? 'unknown');
        $data = $event['data'] ?? null;

        return [
            'site_id' => $site->id,
            'seq' => $event['seq'],
            'occurred_at' => Carbon::createFromTimestamp($event['time'])->utc()->format('Y-m-d H:i:s'),
            'type' => substr($type, 0, 64),
            'object' => self::str($event['object'] ?? '', 191),
            'from_version' => isset($event['from']) ? self::str($event['from'], 191) : null,
            'to_version' => isset($event['to']) ? self::str($event['to'], 191) : null,
            'result' => in_array($event['result'] ?? '', ['success', 'failed'], true) ? $event['result'] : 'success',
            'source' => in_array($source, self::SOURCES, true) ? $source : 'unknown',
            'job_id' => isset($event['job_id']) && is_string($event['job_id']) ? substr($event['job_id'], 0, 64) : null,
            'user_login' => self::str($event['user_login'] ?? '', 60),
            'ip' => filter_var($event['ip'] ?? '', FILTER_VALIDATE_IP) !== false ? $event['ip'] : '',
            'user_agent' => self::str($event['user_agent'] ?? '', 255),
            'data' => is_array($data) && $data !== [] ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function str($value, int $max): string
    {
        return mb_substr(is_scalar($value) ? (string) $value : '', 0, $max);
    }
}
