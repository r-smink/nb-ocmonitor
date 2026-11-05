<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

/**
 * Widget op het WordPress-dashboard met het volgende geplande onderhoud. De gegevens stuurt het
 * panel (site.schedule); de plugin toont alleen wat er is opgeslagen.
 */
final class DashboardWidget
{
    public const OPTION = 'nb_ocm_schedule';

    /**
     * @param mixed $value
     * @return array{next: ?string, last: ?string, contract: ?string, company: string, contact: ?string, updated: int}
     */
    public static function normalize($value): array
    {
        $value = is_array($value) ? $value : [];
        $date = static function ($v): ?string {
            return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 ? $v : null;
        };
        $text = static function ($v, int $max): ?string {
            return is_string($v) && trim($v) !== '' ? mb_substr(trim(wp_strip_all_tags($v)), 0, $max) : null;
        };

        return [
            'next' => $date($value['next'] ?? null),
            'last' => $date($value['last'] ?? null),
            'contract' => $text($value['contract'] ?? null, 60),
            'company' => $text($value['company'] ?? null, 80) ?? 'Next Buzz',
            'contact' => $text($value['contact'] ?? null, 300),
            'updated' => isset($value['updated']) ? (int) $value['updated'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function store(array $args): array
    {
        if (array_diff(array_keys($args), ['next', 'last', 'contract', 'company', 'contact']) !== []) {
            throw new \NextBuzz\OcMonitor\Client\CommandError('bad_request', 'Onbekend veld');
        }
        $data = self::normalize($args + ['updated' => time()]);
        update_option(self::OPTION, $data, false);

        return $data;
    }

    public static function register(): void
    {
        add_action('wp_dashboard_setup', static function (): void {
            $data = self::normalize(get_option(self::OPTION));
            if (!current_user_can('edit_posts') || ($data['next'] === null && $data['last'] === null && $data['contract'] === null)) {
                return;
            }
            wp_add_dashboard_widget('nb_ocm_maintenance', 'Onderhoud door ' . esc_html($data['company']), static function () use ($data): void {
                self::render($data);
            });
        });
    }

    /** @param array<string, mixed> $d */
    private static function render(array $d): void
    {
        $fmt = static function (?string $date): string {
            return $date ? esc_html(wp_date('l j F Y', (int) strtotime($date . ' 12:00:00'))) : '–';
        };
        echo '<div class="nb-ocm-widget">';
        if ($d['next']) {
            $days = (int) floor((strtotime($d['next'] . ' 00:00:00') - strtotime(wp_date('Y-m-d') . ' 00:00:00')) / DAY_IN_SECONDS);
            $when = $days === 0 ? 'vandaag' : ($days === 1 ? 'morgen' : ($days > 1 ? 'over ' . $days . ' dagen' : 'gepland'));
            printf('<p style="margin:0 0 4px;color:#646970">Volgend onderhoud</p><p style="margin:0 0 12px;font-size:18px;font-weight:600">%s <span style="font-weight:400;color:#646970;font-size:13px">(%s)</span></p>', $fmt($d['next']), esc_html($when));
        } else {
            echo '<p style="margin:0 0 12px">Er is nog geen volgend onderhoud gepland.</p>';
        }
        echo '<table class="widefat striped" style="border:0"><tbody>';
        if ($d['last']) {
            printf('<tr><td>Laatste onderhoud</td><td>%s</td></tr>', $fmt($d['last']));
        }
        if ($d['contract']) {
            printf('<tr><td>Onderhoudscontract</td><td>%s</td></tr>', esc_html($d['contract']));
        }
        echo '</tbody></table>';
        echo '<p style="margin:12px 0 0;color:#646970">Bij onderhoud werken we plugins, thema\'s en WordPress bij, met vooraf een backup en een controle van de site.</p>';
        if ($d['contact']) {
            printf('<p style="margin:8px 0 0">%s</p>', esc_html($d['contact']));
        }
        echo '</div>';
    }
}
