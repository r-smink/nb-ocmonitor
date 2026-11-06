<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\Settings;

/**
 * Widget op het WordPress-dashboard in Next Buzz-stijl: volgend en laatste onderhoud, contract,
 * en (als dat bij de klant aan staat) een knop om het laatste onderhoudsrapport te downloaden.
 * De gegevens stuurt het panel (site.schedule); de plugin toont alleen wat er is opgeslagen.
 */
final class DashboardWidget
{
    public const OPTION = 'nb_ocm_schedule';

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function normalize($value, ?string $panelHost = null): array
    {
        $value = is_array($value) ? $value : [];
        $date = static function ($v): ?string {
            return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 ? $v : null;
        };
        $text = static function ($v, int $max): ?string {
            if (!is_string($v) || trim($v) === '') {
                return null;
            }
            $v = trim(strip_tags($v));

            return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
        };
        $color = static function ($v, string $default): string {
            return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/D', $v) === 1 ? strtoupper($v) : $default;
        };
        // De downloadlink mag alleen naar het panel waarmee de site gekoppeld is.
        $report = null;
        $url = $value['report_url'] ?? null;
        if (is_string($url) && strlen($url) <= 1000 && strpos($url, 'https://') === 0 && $panelHost !== null
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower($panelHost)) {
            $report = $url;
        }

        return [
            'next' => $date($value['next'] ?? null),
            'last' => $date($value['last'] ?? null),
            'contract' => $text($value['contract'] ?? null, 60),
            'company' => $text($value['company'] ?? null, 80) ?? 'Next Buzz',
            'contact' => $text($value['contact'] ?? null, 300),
            'report_url' => $report,
            'report_label' => $text($value['report_label'] ?? null, 80),
            'color' => $color($value['color'] ?? null, '#E22830'),
            'accent' => $color($value['accent'] ?? null, '#1F2937'),
            'updated' => isset($value['updated']) ? (int) $value['updated'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function store(array $args): array
    {
        if (array_diff(array_keys($args), ['next', 'last', 'contract', 'company', 'contact', 'report_url', 'report_label', 'color', 'accent']) !== []) {
            throw new CommandError('bad_request', 'Onbekend veld');
        }
        $data = self::normalize($args + ['updated' => time()], self::panelHost());
        update_option(self::OPTION, $data, false);

        return $data;
    }

    private static function panelHost(): ?string
    {
        $config = Settings::load();
        $host = $config ? parse_url($config->panelUrl, PHP_URL_HOST) : null;

        return is_string($host) ? $host : null;
    }

    public static function register(): void
    {
        add_action('wp_dashboard_setup', static function (): void {
            $data = self::normalize(get_option(self::OPTION), self::panelHost());
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
        $fmt = static function (?string $date, string $format = 'j F Y'): string {
            return $date ? esc_html(wp_date($format, (int) strtotime($date . ' 12:00:00'))) : '–';
        };
        $c = esc_attr($d['color']);
        $a = esc_attr($d['accent']);
        echo '<style>'
            . '.nbocm-w{margin:-11px -12px -12px;font-size:13px;color:#1F2937}'
            . '.nbocm-w .hd{background:' . $a . ';color:#fff;padding:16px 18px;display:flex;align-items:center;gap:14px;border-bottom:3px solid ' . $c . '}'
            . '.nbocm-w .cal{background:#fff;color:' . $a . ';border-radius:8px;min-width:54px;text-align:center;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.2)}'
            . '.nbocm-w .cal b{display:block;background:' . $c . ';color:#fff;font-size:10px;letter-spacing:.08em;text-transform:uppercase;padding:2px 0}'
            . '.nbocm-w .cal span{display:block;font-size:22px;font-weight:700;line-height:1.3}'
            . '.nbocm-w .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.75}'
            . '.nbocm-w .big{font-size:16px;font-weight:600;margin-top:2px}'
            . '.nbocm-w .when{display:inline-block;margin-top:4px;background:' . $c . ';color:#fff;border-radius:999px;padding:1px 9px;font-size:11px;font-weight:600}'
            . '.nbocm-w .bd{padding:14px 18px}'
            . '.nbocm-w dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0}'
            . '.nbocm-w dt{color:#6B7280}.nbocm-w dd{margin:0;font-weight:500}'
            . '.nbocm-w .btn{display:inline-flex;align-items:center;gap:6px;margin-top:14px;background:' . $c . ';color:#fff;border-radius:6px;padding:8px 14px;font-weight:600;text-decoration:none}'
            . '.nbocm-w .btn:hover,.nbocm-w .btn:focus{color:#fff;filter:brightness(.92)}'
            . '.nbocm-w .ft{border-top:1px solid #E5E7EB;padding:10px 18px;color:#6B7280;font-size:12px}'
            . '</style>';
        echo '<div class="nbocm-w">';
        echo '<div class="hd">';
        if ($d['next']) {
            $days = (int) floor((strtotime($d['next'] . ' 00:00:00') - strtotime(wp_date('Y-m-d') . ' 00:00:00')) / DAY_IN_SECONDS);
            $when = $days === 0 ? 'vandaag' : ($days === 1 ? 'morgen' : ($days > 1 ? 'over ' . $days . ' dagen' : 'binnenkort'));
            printf('<div class="cal"><b>%s</b><span>%s</span></div>', $fmt($d['next'], 'M'), $fmt($d['next'], 'j'));
            printf('<div><div class="lbl">Volgend onderhoud</div><div class="big">%s</div><span class="when">%s</span></div>', $fmt($d['next'], 'l j F Y'), esc_html($when));
        } else {
            echo '<div><div class="lbl">Volgend onderhoud</div><div class="big">Nog niet gepland</div></div>';
        }
        echo '</div><div class="bd"><dl>';
        if ($d['last']) {
            printf('<dt>Laatste onderhoud</dt><dd>%s</dd>', $fmt($d['last']));
        }
        if ($d['contract']) {
            printf('<dt>Onderhoudscontract</dt><dd>%s</dd>', esc_html($d['contract']));
        }
        echo '</dl>';
        if ($d['report_url']) {
            printf(
                '<a class="btn" href="%s" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-download" aria-hidden="true"></span> %s</a>',
                esc_url($d['report_url']),
                esc_html($d['report_label'] ?? 'Laatste onderhoudsrapport downloaden')
            );
        }
        echo '</div><div class="ft">Bij elk onderhoud werken we plugins, thema\'s en WordPress bij, met vooraf een backup en een controle van de site.';
        if ($d['contact']) {
            printf('<br>%s', esc_html($d['contact']));
        }
        echo '</div></div>';
    }
}
