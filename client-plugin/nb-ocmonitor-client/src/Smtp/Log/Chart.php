<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

/**
 * Staafgrafiek van de e-maillog per dag (inline SVG, zonder JavaScript). Puur: krijgt rijen en
 * geeft markup terug, zodat hij zonder WordPress te testen is.
 */
final class Chart
{
    public const DAYS = 14;

    /** Lagen van onder naar boven: sleutel => [label, kleur]. Kleuren gelijk aan admin.css. */
    public const SERIES = [
        'sent' => ['Verzonden', '#1F9D55'],
        'backup' => ['Via backup', '#E8A317'],
        'failed' => ['Mislukt', '#C41E25'],
    ];

    private const W = 960;
    private const H = 200;
    private const LEFT = 34;
    private const RIGHT = 6;
    private const TOP = 10;
    private const BOTTOM = 26;

    private const DAY_SHORT = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
    private const DAY_LONG = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    private const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

    /**
     * Begin (lokale middernacht, $days - 1 dagen geleden) en eind (middernacht na vandaag) in de tijdzone van de site.
     *
     * @return array{0: int, 1: int}
     */
    public static function range(int $now, \DateTimeZone $tz, int $days = self::DAYS): array
    {
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);

        return [$today->modify('-' . ($days - 1) . ' days')->getTimestamp(), $today->modify('+1 day')->getTimestamp()];
    }

    /**
     * Tellingen per tijdsblok (bijv. per kwartier, begin als unix-tijd) optellen per kalenderdag in $tz.
     * Elke dag in het bereik komt erin, ook zonder mail; zo klopt het ook rond de zomertijd.
     *
     * @param iterable<array{start: int, sent: int, failed: int, backup: int}> $buckets
     * @return list<array{date: string, sent: int, failed: int, backup: int}>
     */
    public static function groupByDay(iterable $buckets, int $since, int $until, \DateTimeZone $tz): array
    {
        $days = [];
        $day = (new \DateTimeImmutable('@' . $since))->setTimezone($tz)->setTime(0, 0);
        while ($day->getTimestamp() < $until) {
            $days[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'sent' => 0, 'failed' => 0, 'backup' => 0];
            $day = $day->modify('+1 day');
        }
        foreach ($buckets as $b) {
            $key = (new \DateTimeImmutable('@' . (int) $b['start']))->setTimezone($tz)->format('Y-m-d');
            if (!isset($days[$key])) {
                continue;
            }
            foreach (['sent', 'failed', 'backup'] as $k) {
                $days[$key][$k] += (int) ($b[$k] ?? 0);
            }
        }

        return array_values($days);
    }

    /** "ma 16" */
    public static function shortLabel(string $date): string
    {
        $d = new \DateTimeImmutable($date);

        return self::DAY_SHORT[(int) $d->format('w')] . ' ' . $d->format('j');
    }

    /** "maandag 16 november" */
    public static function longLabel(string $date): string
    {
        $d = new \DateTimeImmutable($date);

        return self::DAY_LONG[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1];
    }

    /** Mooie bovengrens voor de y-as, deelbaar door 4 in hele stappen van 1, 2 of 5 × 10^n. */
    public static function niceMax(int $max): int
    {
        if ($max <= 4) {
            return 4;
        }
        $raw = $max / 4;
        $pow = 10 ** (int) floor(log10($raw));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $pow >= $raw) {
                return (int) ($m * $pow * 4);
            }
        }

        return (int) (10 * $pow * 4);
    }

    /** @param list<array{date: string, sent: int, failed: int, backup: int}> $days */
    public static function svg(array $days, string $title = 'E-mails per dag'): string
    {
        $n = max(1, count($days));
        $plotW = self::W - self::LEFT - self::RIGHT;
        $plotH = self::H - self::TOP - self::BOTTOM;
        $max = 0;
        foreach ($days as $d) {
            $max = max($max, self::total($d));
        }
        $yMax = self::niceMax($max);
        $slot = $plotW / $n;
        $barW = min(36.0, $slot * 0.62);
        $y = static function (float $v) use ($yMax, $plotH): float {
            return self::TOP + $plotH - $v / $yMax * $plotH;
        };

        $out = sprintf(
            '<svg class="nbocm-chart-svg" viewBox="0 0 %d %d" width="100%%" role="group" aria-label="%s" xmlns="http://www.w3.org/2000/svg" font-family="inherit">',
            self::W,
            self::H,
            self::e($title)
        );
        // Rasterlijnen en y-as
        for ($i = 0; $i <= 4; $i++) {
            $v = $yMax / 4 * $i;
            $yy = self::f($y($v));
            $out .= sprintf('<line x1="%d" x2="%d" y1="%s" y2="%s" stroke="%s" stroke-width="1"/>', self::LEFT, self::W - self::RIGHT, $yy, $yy, $i === 0 ? '#9CA3AF' : '#E5E7EB');
            $out .= sprintf('<text x="%d" y="%s" text-anchor="end" font-size="11" fill="#767674" aria-hidden="true">%d</text>', self::LEFT - 6, self::f($y($v) + 4), (int) $v);
        }
        // Staven
        foreach ($days as $i => $d) {
            $x = self::LEFT + $slot * $i + ($slot - $barW) / 2;
            $label = self::longLabel($d['date']) . ': ' . self::describe($d);
            $out .= sprintf('<g role="img" aria-label="%s"><title>%s</title>', self::e($label), self::e($label));
            // Onzichtbaar vlak over de hele hoogte, zodat de tooltip ook bij lege dagen werkt.
            $out .= sprintf('<rect x="%s" y="%d" width="%s" height="%d" fill="transparent"/>', self::f(self::LEFT + $slot * $i), self::TOP, self::f($slot), $plotH);
            $base = 0;
            foreach (self::SERIES as $key => [, $color]) {
                $v = self::part($d, $key);
                if ($v <= 0) {
                    continue;
                }
                $top = $y($base + $v);
                $out .= sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>', self::f($x), self::f($top), self::f($barW), self::f($y($base) - $top), $color);
                $base += $v;
            }
            $out .= sprintf('<text x="%s" y="%d" text-anchor="middle" font-size="11" fill="#585858" aria-hidden="true">%s</text>', self::f($x + $barW / 2), self::H - 8, self::e(self::shortLabel($d['date'])));
            $out .= '</g>';
        }

        return $out . '</svg>';
    }

    public static function legend(): string
    {
        $out = '<ul class="nbocm-chart-legend">';
        foreach (self::SERIES as [$label, $color]) {
            $out .= sprintf('<li><span class="nbocm-chart-swatch" style="background:%s" aria-hidden="true"></span>%s</li>', $color, self::e($label));
        }

        return $out . '</ul>';
    }

    /** @param array{sent: int, failed: int, backup: int} $d */
    public static function describe(array $d): string
    {
        $backup = self::part($d, 'backup');

        return sprintf('%d verzonden%s, %d mislukt', self::part($d, 'sent') + $backup, $backup > 0 ? sprintf(' (waarvan %d via backup)', $backup) : '', self::part($d, 'failed'));
    }

    /**
     * De rijen tellen "sent" inclusief backup (zoals LogRepository::stats()); in de grafiek is
     * "Verzonden" alleen de primaire verbinding, zodat de lagen optellen tot het totaal.
     *
     * @param array{sent: int, failed: int, backup: int} $d
     */
    public static function part(array $d, string $key): int
    {
        if ($key === 'sent') {
            return max(0, (int) $d['sent'] - (int) $d['backup']);
        }

        return max(0, (int) $d[$key]);
    }

    /** @param array{sent: int, failed: int, backup: int} $d */
    private static function total(array $d): int
    {
        return max((int) $d['sent'], (int) $d['backup']) + max(0, (int) $d['failed']);
    }

    private static function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
