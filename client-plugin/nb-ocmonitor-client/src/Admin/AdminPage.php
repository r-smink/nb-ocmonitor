<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;
use NextBuzz\OcMonitor\Client\OptionFailureLog;
use NextBuzz\OcMonitor\Client\PairingService;
use NextBuzz\OcMonitor\Client\Security\ClientIp;
use NextBuzz\OcMonitor\Client\Security\LoginSecurity;
use NextBuzz\OcMonitor\Client\Security\TwoFactor;
use NextBuzz\OcMonitor\Client\Settings;

/**
 * Extra > NB OC Monitor: status, koppelen en ontkoppelen, inlogbeveiliging, recente events en afgewezen verzoeken.
 */
final class AdminPage
{
    private const SLUG = 'nb-ocmonitor';
    private const CAPABILITY = 'manage_options';

    private PairingService $pairing;
    private EventStore $events;
    private EventRecorder $recorder;

    public function __construct(PairingService $pairing, EventStore $events, EventRecorder $recorder)
    {
        $this->pairing = $pairing;
        $this->events = $events;
        $this->recorder = $recorder;
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_management_page('NB OC Monitor', 'NB OC Monitor', self::CAPABILITY, self::SLUG, [$this, 'render']);
        });
        add_action('admin_post_nb_ocm_pair', [$this, 'handlePair']);
        add_action('admin_post_nb_ocm_unpair', [$this, 'handleUnpair']);
        add_action('admin_post_nb_ocm_login_security', [$this, 'handleLoginSecurity']);
        add_action('admin_post_nb_ocm_unlock', [$this, 'handleUnlock']);
    }

    /** Inlogbeveiliging kan alleen hier (of via WP-CLI) veranderen, niet via het panel. */
    public function handleLoginSecurity(): void
    {
        $this->guard('nb_ocm_login_security');
        $post = wp_unslash($_POST);
        $allow = (string) ($post['nb_ocm_allowlist'] ?? '');
        $invalid = array_filter(preg_split('/[\s,]+/', $allow) ?: [], static function (string $range): bool {
            return $range !== '' && !ClientIp::isValidRange($range);
        });
        $change = LoginSecurity::save([
            'lockout' => !empty($post['nb_ocm_lockout']),
            'max_attempts' => $post['nb_ocm_max_attempts'] ?? null,
            'window_minutes' => $post['nb_ocm_window_minutes'] ?? null,
            'lockout_minutes' => $post['nb_ocm_lockout_minutes'] ?? null,
            'allowlist' => $allow,
            'xmlrpc' => (string) ($post['nb_ocm_xmlrpc'] ?? ''),
            'hide_users' => !empty($post['nb_ocm_hide_users']),
        ]);
        $twoFactor = TwoFactor::setMode((string) ($post['nb_ocm_two_factor'] ?? 'off'));
        if ($twoFactor['from'] !== $twoFactor['to']) {
            $this->recorder->record('ocm.two_factor_changed', 'two_factor', $twoFactor['from'], $twoFactor['to']);
        }
        if ($change['from'] !== $change['to']) {
            $this->recorder->record('ocm.login_security_changed', 'login_security', self::describe($change['from']), self::describe($change['to']));
        }
        $this->redirect($invalid === [] ? 'success' : 'error', $invalid === []
            ? 'Inlogbeveiliging opgeslagen.'
            : 'Opgeslagen, maar deze IP-adressen zijn ongeldig en weggelaten: ' . implode(', ', $invalid));
    }

    public function handleUnlock(): void
    {
        $this->guard('nb_ocm_unlock');
        $count = LoginSecurity::unlockAll();
        $this->redirect('success', sprintf('%d blokkade(s) opgeheven.', $count));
    }

    /** @param array<string, mixed> $config */
    private static function describe(array $config): string
    {
        return sprintf(
            'blokkade %s (%d pogingen/%d min, %d min), xmlrpc %s, gebruikers %s, uitzonderingen %d',
            $config['lockout'] ? 'aan' : 'uit',
            $config['max_attempts'],
            $config['window_minutes'],
            $config['lockout_minutes'],
            $config['xmlrpc'],
            $config['hide_users'] ? 'afgeschermd' : 'openbaar',
            count($config['allowlist'])
        );
    }

    public function handlePair(): void
    {
        $this->guard('nb_ocm_pair');
        $code = isset($_POST['nb_ocm_code']) ? sanitize_text_field(wp_unslash((string) $_POST['nb_ocm_code'])) : '';
        try {
            $result = $this->pairing->pair($code);
            $this->redirect('success', sprintf('Gekoppeld aan %s als %s.', $result['panel_url'], $result['site_id']));
        } catch (\RuntimeException $e) {
            $this->redirect('error', $e->getMessage());
        }
    }

    public function handleUnpair(): void
    {
        $this->guard('nb_ocm_unpair');
        if (($_POST['nb_ocm_confirm'] ?? '') !== 'ONTKOPPEL') {
            $this->redirect('error', 'Typ ONTKOPPEL om te bevestigen.');
        }
        $this->pairing->unpair();
        $this->redirect('success', 'De site is ontkoppeld. De sleutels zijn verwijderd.');
    }

    private function renderLoginSecurity(): void
    {
        $c = LoginSecurity::config();
        $locked = LoginSecurity::activeLockouts(time());
        $url = esc_url(admin_url('admin-post.php'));

        echo '<h2>Inlogbeveiliging</h2>';
        echo '<p>Inloggen via wp-login.php, de blokeditor en de WordPress-app met een applicatiewachtwoord blijven gewoon werken.</p>';
        echo '<form method="post" action="' . $url . '">';
        wp_nonce_field('nb_ocm_login_security');
        echo '<input type="hidden" name="action" value="nb_ocm_login_security">';
        echo '<table class="form-table" role="presentation"><tbody>';

        printf(
            '<tr><th scope="row">Blokkeren na mislukte pogingen</th><td><label><input type="checkbox" name="nb_ocm_lockout" value="1"%s> Blokkeer een IP-adres na</label> '
            . '<input type="number" name="nb_ocm_max_attempts" value="%d" min="3" max="50" class="small-text"> pogingen binnen '
            . '<input type="number" name="nb_ocm_window_minutes" value="%d" min="1" max="1440" class="small-text"> minuten, voor '
            . '<input type="number" name="nb_ocm_lockout_minutes" value="%d" min="1" max="1440" class="small-text"> minuten.'
            . '<p class="description">Na drie blokkades binnen een dag duurt de blokkade 24 uur. Achter Cloudflare of een proxy op de server wordt het echte IP-adres gebruikt.</p></td></tr>',
            $c['lockout'] ? ' checked' : '',
            $c['max_attempts'],
            $c['window_minutes'],
            $c['lockout_minutes']
        );
        printf(
            '<tr><th scope="row"><label for="nb_ocm_allowlist">Nooit blokkeren</label></th><td><textarea id="nb_ocm_allowlist" name="nb_ocm_allowlist" rows="3" class="large-text code" placeholder="203.0.113.10&#10;198.51.100.0/24">%s</textarea>'
            . '<p class="description">IP-adressen of ranges (CIDR), één per regel. Bijvoorbeeld het kantoor. Jouw huidige IP-adres: <code>%s</code></p></td></tr>',
            esc_textarea(implode("\n", $c['allowlist'])),
            esc_html(ClientIp::resolve($_SERVER))
        );
        echo '<tr><th scope="row"><label for="nb_ocm_xmlrpc">XML-RPC-login</label></th><td><select id="nb_ocm_xmlrpc" name="nb_ocm_xmlrpc">';
        foreach (LoginSecurity::XMLRPC_MODES as $mode => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($mode), selected($c['xmlrpc'], $mode, false), esc_html($label));
        }
        echo '</select><p class="description">Brute-force-aanvallen lopen vaak via xmlrpc.php. Uit = geen inloggen via XML-RPC, geen system.multicall en geen pingbacks. Jetpack heeft XML-RPC nodig.</p></td></tr>';
        printf(
            '<tr><th scope="row">Gebruikersnamen afschermen</th><td><label><input type="checkbox" name="nb_ocm_hide_users" value="1"%s> Voor bezoekers die niet zijn ingelogd: geen gebruikerslijst via de REST API (<code>/wp-json/wp/v2/users</code>), geen <code>?author=1</code> en geen gebruikers in de sitemap.</label></td></tr>',
            $c['hide_users'] ? ' checked' : ''
        );
        echo '<tr><th scope="row"><label for="nb_ocm_two_factor">Tweestapsverificatie afdwingen</label></th><td><select id="nb_ocm_two_factor" name="nb_ocm_two_factor">';
        foreach (TwoFactor::MODES as $mode => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($mode), selected(TwoFactor::mode(), $mode, false), esc_html($label));
        }
        echo '</select><p class="description">Wie al een 2FA-plugin gebruikt (Two Factor, Wordfence, WP 2FA) merkt niets. Anderen krijgen na het wachtwoord een code per e-mail; controleer dus of de site mail kan versturen. Noodstop: <code>define(\'NB_OCM_DISABLE_2FA\', true);</code> in wp-config.php.'
            . (TwoFactor::disabledByConstant() ? ' <strong>Staat nu uit via NB_OCM_DISABLE_2FA.</strong>' : '') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Inlogbeveiliging opslaan', 'secondary');
        echo '</form>';

        if ($locked !== []) {
            echo '<h3>Nu geblokkeerd</h3><table class="widefat striped" style="max-width:760px"><thead><tr><th>IP-range</th><th>Tot</th><th>Blokkades vandaag</th></tr></thead><tbody>';
            foreach (array_slice($locked, 0, 50) as $entry) {
                printf('<tr><td>%s</td><td>%s</td><td>%d</td></tr>', esc_html((string) $entry['ip']), esc_html(wp_date('Y-m-d H:i', (int) $entry['until'])), (int) $entry['locks']);
            }
            echo '</tbody></table>';
            echo '<form method="post" action="' . $url . '">';
            wp_nonce_field('nb_ocm_unlock');
            echo '<input type="hidden" name="action" value="nb_ocm_unlock">';
            submit_button(sprintf('Alle %d blokkades opheffen', count($locked)), 'secondary small');
            echo '</form>';
        }
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $summary = Settings::summary();
        $paired = Settings::isPaired();
        $failures = OptionFailureLog::read();
        $notice = get_transient($this->noticeKey());
        delete_transient($this->noticeKey());

        echo '<div class="wrap"><h1>NB OC Monitor</h1>';

        if (is_array($notice)) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', $notice['type'] === 'success' ? 'success' : 'error', esc_html($notice['message']));
        }

        echo '<h2>Status</h2><table class="widefat striped" style="max-width:760px"><tbody>';
        $this->row('Gekoppeld', $paired ? 'Ja' : 'Nee');
        if ($paired) {
            $this->row('Panel', (string) $summary['panel_url']);
            $this->row('Site-id', (string) $summary['site_id']);
            $this->row('Panel-sleutel(s)', implode(', ', (array) $summary['panel_key_ids']));
            $this->row('Site-sleutel', (string) $summary['site_key_id'] . ($summary['site_key_in_wp_config'] ? ' (uit wp-config.php)' : ''));
            $this->row('Scopes', implode(', ', (array) $summary['scopes']));
            $this->row('Gekoppeld op', $summary['paired_at'] ? wp_date('Y-m-d H:i', (int) $summary['paired_at']) : '-');
        }
        $this->row('Plugin-versie', NB_OCM_VERSION);
        $this->row('Noodstop', 'Zet <code>define(\'NB_OCM_DISABLED\', true);</code> in wp-config.php om de koppeling direct uit te schakelen.', true);
        echo '</tbody></table>';

        if (!$paired) {
            echo '<h2>Koppelen</h2><p>Maak in het panel een site aan en plak hier de koppelcode (15 minuten geldig).</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('nb_ocm_pair');
            echo '<input type="hidden" name="action" value="nb_ocm_pair">';
            echo '<textarea name="nb_ocm_code" rows="4" class="large-text code" required autocomplete="off" spellcheck="false"></textarea>';
            submit_button('Koppelen');
            echo '</form>';
        } else {
            echo '<h2>Vanuit het panel</h2><p>Het panel kan de site uitlezen, het wijzigingslog ophalen, plugins, thema\'s, vertalingen en WordPress bijwerken (alleen wat WordPress zelf aanbiedt), deze plugin bijwerken naar een release die met de release-sleutel van Next Buzz is ondertekend, en beheerders blokkeren, uitloggen, terugzetten of een wachtwoordreset laten doen. Het panel kan geen gebruikers aanmaken, geen wachtwoorden kiezen en geen eigen code sturen. Wil je dat stoppen, ontkoppel de site of zet de noodstop.</p>';
        }

        $this->renderLoginSecurity();

        if ($paired) {
            echo '<h2>Ontkoppelen</h2><p>Verwijdert de sleutels. Het panel kan daarna niets meer op deze site uitvoeren.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('nb_ocm_unpair');
            echo '<input type="hidden" name="action" value="nb_ocm_unpair">';
            echo '<p><label>Typ ONTKOPPEL ter bevestiging: <input type="text" name="nb_ocm_confirm" autocomplete="off"></label></p>';
            submit_button('Ontkoppelen', 'delete');
            echo '</form>';
        }

        echo '<h2>Recente wijzigingen</h2><table class="widefat striped"><thead><tr><th>#</th><th>Tijd</th><th>Type</th><th>Object</th><th>Van</th><th>Naar</th><th>Resultaat</th><th>Bron</th><th>Gebruiker</th></tr></thead><tbody>';
        $latest = $this->events->latest(25);
        if ($latest === []) {
            echo '<tr><td colspan="9">Nog geen wijzigingen vastgelegd.</td></tr>';
        }
        foreach ($latest as $event) {
            printf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int) $event['seq'],
                esc_html(wp_date('Y-m-d H:i', (int) $event['time'])),
                esc_html((string) $event['type']),
                esc_html((string) $event['object']),
                esc_html((string) ($event['from'] ?? '')),
                esc_html((string) ($event['to'] ?? '')),
                esc_html((string) $event['result']),
                esc_html((string) $event['source']),
                esc_html((string) $event['user_login'])
            );
        }
        echo '</tbody></table>';

        echo '<h2>Afgewezen verzoeken</h2><p class="description">Steekproef: hoogstens één registratie per minuut, zodat een aanvaller geen schrijfacties kan afdwingen.</p>';
        echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Tijd</th><th>Reden</th><th>IP</th></tr></thead><tbody>';
        if ($failures['recent'] === []) {
            echo '<tr><td colspan="3">Geen.</td></tr>';
        }
        foreach ($failures['recent'] as $failure) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html(wp_date('Y-m-d H:i', (int) $failure['time'])), esc_html((string) $failure['reason']), esc_html((string) $failure['ip']));
        }
        echo '</tbody></table></div>';
    }

    private function row(string $label, string $value, bool $allowCode = false): void
    {
        printf('<tr><th style="width:200px">%s</th><td>%s</td></tr>', esc_html($label), $allowCode ? wp_kses($value, ['code' => []]) : esc_html($value));
    }

    private function guard(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Geen toegang.', 403);
        }
        check_admin_referer($action);
    }

    private function redirect(string $type, string $message): void
    {
        set_transient($this->noticeKey(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('tools.php?page=' . self::SLUG));
        exit;
    }

    private function noticeKey(): string
    {
        return 'nb_ocm_notice_' . get_current_user_id();
    }
}
