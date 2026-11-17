<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\Settings;

/**
 * Widget op het WordPress-dashboard in Next Buzz-stijl: volgend en laatste onderhoud, contract,
 * statuslampjes (SSL, uptime, e-mail) en (als dat bij de klant aan staat) een knop om het laatste
 * onderhoudsrapport te downloaden.
 * De gegevens stuurt het panel (site.schedule); de plugin toont alleen wat er is opgeslagen.
 */
final class DashboardWidget
{
    public const OPTION = 'nb_ocm_schedule';

    /** Zoveel rapporten in de onderhoudshistorie. */
    public const MAX_REPORTS = 12;

    /** Zo kort voor het verlopen van het SSL-certificaat kleurt het lampje oranje. */
    public const SSL_WARN_DAYS = 14;

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function normalize($value, ?string $panelHost = null): array
    {
        $value = is_array($value) ? $value : [];
        $date = static function ($v): ?string {
            return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
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
        // Downloadlinks mogen alleen naar het panel waarmee de site gekoppeld is.
        $panelUrl = static function ($url) use ($panelHost): ?string {
            return is_string($url) && strlen($url) <= 1000 && strpos($url, 'https://') === 0 && $panelHost !== null
                && strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower($panelHost) ? $url : null;
        };
        $report = $panelUrl($value['report_url'] ?? null);
        $bool = static function ($v): ?bool {
            return is_bool($v) ? $v : null;
        };

        // Status (panel 0.16.0+): SSL en uptime zoals de monitor van het panel ze zag.
        $status = is_array($value['status'] ?? null) ? $value['status'] : [];
        $ssl = is_array($status['ssl'] ?? null) ? $status['ssl'] : [];
        $up = is_array($status['uptime'] ?? null) ? $status['uptime'] : [];
        $percent = $up['percent'] ?? null;
        $percent = is_int($percent) || is_float($percent) ? round(max(0.0, min(100.0, (float) $percent)), 2) : null;
        $downSince = $up['down_since'] ?? null;
        $downSince = is_string($downSince) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $downSince) === 1
            && strtotime($downSince) !== false ? $downSince : null;

        // Onderhoudshistorie (panel 0.16.0+): hoogstens 12, alleen met geldige datum en link.
        $reports = [];
        foreach (is_array($value['reports'] ?? null) ? array_values($value['reports']) : [] as $item) {
            if (count($reports) >= self::MAX_REPORTS) {
                break;
            }
            $itemDate = is_array($item) ? $date($item['date'] ?? null) : null;
            $itemUrl = is_array($item) ? $panelUrl($item['url'] ?? null) : null;
            if ($itemDate === null || $itemUrl === null) {
                continue;
            }
            $reports[] = ['date' => $itemDate, 'label' => $text($item['label'] ?? null, 80) ?? 'Onderhoud ' . $itemDate, 'url' => $itemUrl];
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
            'status' => [
                'ssl' => ['ok' => $bool($ssl['ok'] ?? null), 'expires' => $date($ssl['expires'] ?? null)],
                'uptime' => ['ok' => $bool($up['ok'] ?? null), 'percent' => $percent, 'down_since' => $downSince],
            ],
            'reports' => $reports,
            'updated' => isset($value['updated']) ? (int) $value['updated'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function store(array $args): array
    {
        if (array_diff(array_keys($args), ['next', 'last', 'contract', 'company', 'contact', 'report_url', 'report_label', 'color', 'accent', 'status', 'reports']) !== []) {
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

    /** @return array<string, mixed> de opgeslagen onderhoudsgegevens (ook voor de instellingenpagina) */
    public static function data(): array
    {
        return self::normalize(get_option(self::OPTION), self::panelHost());
    }

    /**
     * Statuslampjes voor de widget en de instellingenpagina: SSL en uptime uit het panel, e-mail
     * lokaal uit de maillog (alleen als de SMTP-module aan staat). Zonder gegevens geen lampje.
     *
     * @param array<string, mixed> $d genormaliseerde gegevens (data())
     * @param array{sent?: int, failed?: int, total?: int}|null $mail maillog over de laatste 7 dagen
     * @return list<array{key: string, tone: string, label: string}> tone: ok, warn of crit
     */
    public static function statusLights(array $d, ?array $mail = null, ?int $now = null, ?\DateTimeZone $tz = null): array
    {
        $now = $now ?? time();
        $tz = $tz ?? (function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC'));
        $local = static function (int $ts, string $format) use ($tz): string {
            return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($format);
        };
        $months = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
        $short = static function (string $ymd) use ($months): string {
            [$y, $m, $day] = array_map('intval', explode('-', $ymd));

            return $day . ' ' . $months[$m - 1] . ' ' . $y;
        };
        $lights = [];

        $ssl = $d['status']['ssl'] ?? [];
        if (($ssl['ok'] ?? null) === false) {
            $lights[] = ['key' => 'ssl', 'tone' => 'crit', 'label' => 'SSL-probleem'];
        } elseif (($ssl['ok'] ?? null) === true) {
            $expires = $ssl['expires'] ?? null;
            if (!is_string($expires)) {
                $lights[] = ['key' => 'ssl', 'tone' => 'ok', 'label' => 'SSL geldig'];
            } else {
                $days = (int) round((strtotime($expires . ' 00:00:00 UTC') - strtotime($local($now, 'Y-m-d') . ' 00:00:00 UTC')) / 86400);
                if ($days < 0) {
                    $lights[] = ['key' => 'ssl', 'tone' => 'crit', 'label' => 'SSL verlopen'];
                } elseif ($days <= self::SSL_WARN_DAYS) {
                    $lights[] = ['key' => 'ssl', 'tone' => 'warn', 'label' => $days === 0 ? 'SSL verloopt vandaag' : ($days === 1 ? 'SSL verloopt morgen' : 'SSL verloopt over ' . $days . ' dagen')];
                } else {
                    $lights[] = ['key' => 'ssl', 'tone' => 'ok', 'label' => 'SSL geldig tot ' . $short($expires)];
                }
            }
        }

        $up = $d['status']['uptime'] ?? [];
        if (($up['ok'] ?? null) === true) {
            $percent = $up['percent'] ?? null;
            if (is_float($percent) || is_int($percent)) {
                $shown = $percent >= 100 ? '100' : number_format(floor((float) $percent * 100) / 100, 2, ',', '.');
                $lights[] = ['key' => 'uptime', 'tone' => $percent < 99 ? 'warn' : 'ok', 'label' => 'Online · ' . $shown . '% (30 dagen)'];
            } else {
                $lights[] = ['key' => 'uptime', 'tone' => 'ok', 'label' => 'Online'];
            }
        } elseif (($up['ok'] ?? null) === false) {
            $since = is_string($up['down_since'] ?? null) ? strtotime($up['down_since']) : false;
            $label = 'Offline';
            if ($since !== false) {
                $label .= ' sinds ' . ($local($since, 'Y-m-d') === $local($now, 'Y-m-d') ? '' : $short($local($since, 'Y-m-d')) . ' ') . $local($since, 'H:i');
            }
            $lights[] = ['key' => 'uptime', 'tone' => 'crit', 'label' => $label];
        }

        if ($mail !== null && (int) ($mail['total'] ?? 0) > 0) {
            $failed = (int) ($mail['failed'] ?? 0);
            if ($failed === 0) {
                $lights[] = ['key' => 'mail', 'tone' => 'ok', 'label' => 'E-mail: alles verstuurd (7 dagen)'];
            } else {
                $lights[] = ['key' => 'mail', 'tone' => (int) ($mail['sent'] ?? 0) === 0 ? 'crit' : 'warn', 'label' => 'E-mail: ' . $failed . ' mislukt (7 dagen)'];
            }
        }

        return $lights;
    }

    /** @return array{sent: int, failed: int, backup: int, total: int}|null maillog van de laatste 7 dagen, of null als de module uit staat */
    public static function mailStats(): ?array
    {
        try {
            if (!\NextBuzz\OcMonitor\Client\Smtp\Module::enabled()) {
                return null;
            }

            return (new \NextBuzz\OcMonitor\Client\Smtp\Log\LogRepository())->stats(time() - 7 * DAY_IN_SECONDS);
        } catch (\Throwable $e) {
            return null; // geen maillog (tabel ontbreekt of database-fout): geen lampje
        }
    }

    public static function register(): void
    {
        add_action('wp_dashboard_setup', static function (): void {
            $data = self::data();
            if (!current_user_can('edit_posts') || ($data['next'] === null && $data['last'] === null && $data['contract'] === null)) {
                return;
            }
            // Bovenaan de eerste kolom (prioriteit high), in plaats van onderaan na de standaardwidgets.
            wp_add_dashboard_widget('nb_ocm_maintenance', 'Onderhoud door ' . esc_html($data['company']), static function () use ($data): void {
                self::render($data);
            }, null, null, 'normal', 'high');
            self::placeOnTopOnce();
        });
    }

    /**
     * Wie de widgets ooit heeft versleept, heeft een eigen volgorde opgeslagen; daarin stond deze widget
     * onderaan. Eén keer per gebruiker zetten we hem bovenaan; versleept iemand hem daarna, dan blijft dat zo.
     */
    private static function placeOnTopOnce(): void
    {
        $userId = get_current_user_id();
        if ($userId === 0 || get_user_meta($userId, 'nb_ocm_widget_placed', true)) {
            return;
        }
        update_user_meta($userId, 'nb_ocm_widget_placed', 1);
        $order = get_user_option('meta-box-order_dashboard', $userId);
        if (!is_array($order) || $order === []) {
            return; // geen eigen volgorde: de prioriteit high doet het werk
        }
        update_user_option($userId, 'meta-box-order_dashboard', self::moveToTop($order, 'nb_ocm_maintenance'), true);
    }

    /**
     * @param array<string, mixed> $order kolom => "id1,id2,..." (zoals WordPress het opslaat)
     * @return array<string, string>
     */
    public static function moveToTop(array $order, string $id): array
    {
        $out = [];
        foreach ($order as $column => $ids) {
            $list = array_values(array_filter(explode(',', (string) $ids), static function (string $x) use ($id): bool {
                return $x !== '' && $x !== $id;
            }));
            $out[(string) $column] = implode(',', $list);
        }
        $out['normal'] = ltrim($id . ',' . ($out['normal'] ?? ''), ',');
        $out['normal'] = rtrim($out['normal'], ',');

        return $out;
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
            . '.nbocm-w .btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.nbocm-w .btns .btn{margin-top:0}'
            . '.nbocm-w .btn.sec{background:#fff;color:' . $a . ';border:1px solid #D1D5DB}'
            . '.nbocm-w .btn.sec:hover,.nbocm-w .btn.sec:focus{color:' . $a . ';border-color:' . $a . ';filter:none}'
            . '.nbocm-w .ft{border-top:1px solid #E5E7EB;padding:10px 18px;color:#6B7280;font-size:12px}'
            . '.nbocm-w .st{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px;padding:0 0 12px;list-style:none;border-bottom:1px solid #F3F4F6}'
            . '.nbocm-w .st li{display:inline-flex;align-items:center;gap:6px;margin:0;background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;border-radius:999px;padding:2px 10px 2px 8px;font-size:12px;font-weight:500;line-height:18px}'
            . '.nbocm-w .st i{width:8px;height:8px;border-radius:50%;flex:none;background:#16A34A}'
            . '.nbocm-w .st .warn{background:#FFFBEB;border-color:#FDE68A;color:#92400E}.nbocm-w .st .warn i{background:#D97706}'
            . '.nbocm-w .st .crit{background:#FEF2F2;border-color:#FECACA;color:#991B1B}.nbocm-w .st .crit i{background:#DC2626}'
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
        echo '</div><div class="bd">';
        $lights = self::statusLights($d, self::mailStats());
        if ($lights !== []) {
            echo '<ul class="st" aria-label="Status van de site">';
            foreach ($lights as $light) {
                printf('<li class="%s"><i aria-hidden="true"></i>%s</li>', esc_attr($light['tone']), esc_html($light['label']));
            }
            echo '</ul>';
        }
        echo '<dl>';
        if ($d['last']) {
            printf('<dt>Laatste onderhoud</dt><dd>%s</dd>', $fmt($d['last']));
        }
        if ($d['contract']) {
            printf('<dt>Onderhoudscontract</dt><dd>%s</dd>', esc_html($d['contract']));
        }
        echo '</dl>';
        $admin = current_user_can(Ui::CAPABILITY);
        if ($d['report_url'] || $admin) {
            echo '<div class="btns">';
            if ($d['report_url']) {
                printf(
                    '<a class="btn" href="%s" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-download" aria-hidden="true"></span> %s</a>',
                    esc_url($d['report_url']),
                    esc_html($d['report_label'] ?? 'Laatste onderhoudsrapport downloaden')
                );
            }
            if ($admin) {
                printf('<a class="btn sec" href="%s"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> NB OC Monitor</a>', esc_url(Ui::url()));
            }
            echo '</div>';
        }
        echo '</div><div class="ft">Bij elk onderhoud werken we plugins, thema\'s en WordPress bij, met vooraf een backup en een controle van de site.';
        if ($d['contact']) {
            printf('<br>%s', esc_html($d['contact']));
        }
        echo '</div></div>';
    }
}
