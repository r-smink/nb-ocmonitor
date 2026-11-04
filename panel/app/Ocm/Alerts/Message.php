<?php

namespace App\Ocm\Alerts;

/** Tekst van een melding, voor e-mail, Slack en Teams. */
final class Message
{
    public const KINDS = ['open' => 'Nieuw', 'escalate' => 'Nu kritiek', 'reminder' => 'Nog steeds open', 'resolve' => 'Opgelost'];

    /** @param list<array<string, mixed>> $items */
    public static function title(array $items): string
    {
        $open = count(array_filter($items, fn ($i) => $i['kind'] !== 'resolve'));
        $resolved = count($items) - $open;
        $crit = count(array_filter($items, fn ($i) => $i['kind'] !== 'resolve' && $i['level'] === 'crit'));
        $sites = count(array_unique(array_map(fn ($i) => $i['site']['id'], $items)));
        if (count($items) === 1) {
            $i = $items[0];

            return ($i['kind'] === 'resolve' ? 'Opgelost' : ($i['level'] === 'crit' ? 'Kritiek' : 'Aandacht')) . ': ' . $i['site']['name'] . ' – ' . $i['text'];
        }
        $parts = array_filter([
            $open ? $open . ' ' . ($open === 1 ? 'melding' : 'meldingen') . ($crit ? " ({$crit} kritiek)" : '') : null,
            $resolved ? $resolved . ' opgelost' : null,
        ]);

        return implode(', ', $parts) . ' op ' . $sites . ' ' . ($sites === 1 ? 'site' : 'sites');
    }

    /** @param array<string, mixed> $i */
    public static function line(array $i): string
    {
        $badge = $i['kind'] === 'resolve' ? '[opgelost]' : ($i['level'] === 'crit' ? '[kritiek]' : '[aandacht]');

        return "{$badge} {$i['site']['name']} · " . self::rest($i);
    }

    /** Categorie, tekst en toelichting, zonder sitenaam. @param array<string, mixed> $i */
    public static function rest(array $i): string
    {
        $suffix = match ($i['kind']) {
            'resolve' => ' (duurde ' . $i['duration'] . ')',
            'escalate' => ' (nu kritiek)',
            'reminder' => ' (open sinds ' . $i['duration'] . ')',
            default => '',
        };

        return "{$i['category_label']}: {$i['text']}{$suffix}";
    }

    /** @param list<array<string, mixed>> $items */
    public static function slack(array $items): string
    {
        $esc = fn (string $t) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $t);
        $lines = array_map(function ($i) use ($esc) {
            $icon = $i['kind'] === 'resolve' ? ':white_check_mark:' : ($i['level'] === 'crit' ? ':red_circle:' : ':large_orange_circle:');

            return "{$icon} <{$i['link']}|" . $esc($i['site']['name']) . '> · ' . $esc(self::rest($i));
        }, array_slice($items, 0, 40));
        if (count($items) > 40) {
            $lines[] = '… en ' . (count($items) - 40) . ' meer';
        }

        return '*' . $esc(self::title($items)) . "*\n" . implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function teamsCard(array $items): array
    {
        $body = [['type' => 'TextBlock', 'text' => self::title($items), 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true]];
        foreach (array_slice($items, 0, 40) as $i) {
            $body[] = [
                'type' => 'TextBlock', 'wrap' => true, 'spacing' => 'Small',
                'color' => $i['kind'] === 'resolve' ? 'Good' : ($i['level'] === 'crit' ? 'Attention' : 'Warning'),
                'text' => '[' . $i['site']['name'] . '](' . $i['link'] . ') · ' . self::rest($i),
            ];
        }

        return ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => $body];
    }
}
