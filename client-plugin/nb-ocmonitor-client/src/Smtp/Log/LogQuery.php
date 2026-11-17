<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

/**
 * Filters van de e-maillog (status, zoeken, datum) en de WHERE-clausule met placeholders voor
 * $wpdb->prepare(). Zonder WordPress, zodat het te testen is.
 */
final class LogQuery
{
    public const PER_PAGE = 50;
    public const STATUSES = ['all' => 'Alle', 'sent' => 'Verzonden', 'failed' => 'Mislukt', 'backup' => 'Via backup', 'test' => 'Test'];

    /**
     * @param array<string, mixed> $in bijvoorbeeld $_GET (al wp_unslash()'d)
     * @return array{status: string, s: string, from: string, to: string, paged: int}
     */
    public static function normalize(array $in): array
    {
        $status = is_string($in['status'] ?? null) ? $in['status'] : 'all';
        $search = is_scalar($in['s'] ?? null) ? MailParts::clip(MailParts::oneLine((string) $in['s']), 200) : '';
        $date = static function ($v): string {
            return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : '';
        };
        $paged = is_numeric($in['paged'] ?? null) ? (int) $in['paged'] : 1;

        return [
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            's' => $search,
            'from' => $date($in['from'] ?? null),
            'to' => $date($in['to'] ?? null),
            'paged' => max(1, min($paged, 100000)),
        ];
    }

    /**
     * Datums (Y-m-d, in de tijdzone van de site) naar een halfopen interval [van, tot).
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function range(string $from, string $to, \DateTimeZone $tz): array
    {
        $start = $from !== '' ? (new \DateTimeImmutable($from . ' 00:00:00', $tz))->getTimestamp() : null;
        $end = $to !== '' ? (new \DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp() : null;

        return [$start, $end];
    }

    /**
     * @param array{status: string, s: string, from: string, to: string, paged: int} $f
     * @return array{0: string, 1: list<string|int>} SQL met %s/%d en de waarden
     */
    public static function where(array $f, ?int $start, ?int $end): array
    {
        $sql = [];
        $args = [];
        switch ($f['status']) {
            case 'sent':
                $sql[] = 'status = %s';
                $args[] = 'sent';
                break;
            case 'failed':
                $sql[] = 'status = %s';
                $args[] = 'failed';
                break;
            case 'backup':
                $sql[] = 'status = %s AND connection = %s';
                $args[] = 'sent';
                $args[] = 'backup';
                break;
            case 'test':
                $sql[] = 'is_test = %d';
                $args[] = 1;
                break;
        }
        if ($f['s'] !== '') {
            $like = '%' . self::escapeLike($f['s']) . '%';
            $sql[] = '(to_email LIKE %s OR subject LIKE %s OR error LIKE %s OR initiator LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        if ($start !== null) {
            $sql[] = 'created_at >= %d';
            $args[] = $start;
        }
        if ($end !== null) {
            $sql[] = 'created_at < %d';
            $args[] = $end;
        }

        return [$sql === [] ? '1=1' : implode(' AND ', $sql), $args];
    }

    /** Gelijk aan $wpdb->esc_like(). */
    public static function escapeLike(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public static function pages(int $total, int $perPage = self::PER_PAGE): int
    {
        return max(1, (int) ceil($total / max(1, $perPage)));
    }

    public static function offset(int $paged, int $perPage = self::PER_PAGE): int
    {
        return (max(1, $paged) - 1) * $perPage;
    }

    /**
     * Querystring-argumenten die bij de huidige filters horen (voor links en export).
     *
     * @param array{status: string, s: string, from: string, to: string, paged: int} $f
     * @return array<string, string|int>
     */
    public static function args(array $f, bool $withPage = false): array
    {
        $out = array_filter(['status' => $f['status'] === 'all' ? '' : $f['status'], 's' => $f['s'], 'from' => $f['from'], 'to' => $f['to']], static function ($v): bool {
            return $v !== '';
        });
        if ($withPage && $f['paged'] > 1) {
            $out['paged'] = $f['paged'];
        }

        return $out;
    }
}
