<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;
use NextBuzz\OcMonitor\Client\OptionFailureLog;
use NextBuzz\OcMonitor\Client\PairingService;
use NextBuzz\OcMonitor\Client\Security\ClientIp;
use NextBuzz\OcMonitor\Client\Security\LoginSecurity;
use NextBuzz\OcMonitor\Client\Security\PanelLogin;
use NextBuzz\OcMonitor\Client\Security\TwoFactor;
use NextBuzz\OcMonitor\Client\Settings;
use NextBuzz\OcMonitor\Client\Smtp\Module as SmtpModule;

/**
 * Menu NB OC Monitor: tabbladen Overzicht (koppeling), Beveiliging, SMTP (als de module aan staat
 * in het panel) en Logboek. In Next Buzz-stijl (assets/admin.css), net als de dashboardwidget.
 */
final class AdminPage
{
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
            $hook = add_menu_page('NB OC Monitor', 'NB OC Monitor', Ui::CAPABILITY, Ui::SLUG, [$this, 'render'], self::menuIcon(), 81);
            add_action('admin_print_styles-' . $hook, static function (): void {
                wp_enqueue_style('nb-ocm-admin', plugins_url('assets/admin.css', NB_OCM_FILE), [], NB_OCM_VERSION);
            });
        });
        // Tot 0.15.0 stond de pagina onder Extra; oude links en bladwijzers doorsturen.
        add_action('admin_page_access_denied', static function (): void {
            global $pagenow;
            if ($pagenow === 'tools.php' && ($_GET['page'] ?? '') === Ui::SLUG) {
                wp_safe_redirect(Ui::url());
                exit;
            }
        });
        add_action('admin_post_nb_ocm_pair', [$this, 'handlePair']);
        add_action('admin_post_nb_ocm_unpair', [$this, 'handleUnpair']);
        add_action('admin_post_nb_ocm_login_security', [$this, 'handleLoginSecurity']);
        add_action('admin_post_nb_ocm_unlock', [$this, 'handleUnlock']);
    }

    /** @return array<string, string> */
    private static function tabs(): array
    {
        $tabs = ['overzicht' => 'Overzicht', 'beveiliging' => 'Beveiliging'];
        if (SmtpModule::enabled()) {
            $tabs['smtp'] = 'SMTP';
            $tabs['maillog'] = 'E-maillog';
            $tabs['meldingen'] = 'Meldingen';
        }
        $tabs['logboek'] = 'Logboek';

        return $tabs;
    }

    /** Inlogbeveiliging kan alleen hier (of via WP-CLI) veranderen, niet via het panel. */
    public function handleLoginSecurity(): void
    {
        Ui::guard('nb_ocm_login_security');
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
        $panelLogin = !empty($post['nb_ocm_panel_login']);
        if ($panelLogin !== PanelLogin::enabled()) {
            PanelLogin::setEnabled($panelLogin);
            $this->recorder->record('ocm.panel_login_changed', 'panel_login', $panelLogin ? 'uit' : 'aan', $panelLogin ? 'aan' : 'uit');
        }
        $twoFactor = TwoFactor::setMode((string) ($post['nb_ocm_two_factor'] ?? 'off'));
        if ($twoFactor['from'] !== $twoFactor['to']) {
            $this->recorder->record('ocm.two_factor_changed', 'two_factor', $twoFactor['from'], $twoFactor['to']);
        }
        if ($change['from'] !== $change['to']) {
            $this->recorder->record('ocm.login_security_changed', 'login_security', self::describe($change['from']), self::describe($change['to']));
        }
        Ui::redirect('beveiliging', $invalid === [] ? 'success' : 'error', $invalid === []
            ? 'Inlogbeveiliging opgeslagen.'
            : 'Opgeslagen, maar deze IP-adressen zijn ongeldig en weggelaten: ' . implode(', ', $invalid));
    }

    public function handleUnlock(): void
    {
        Ui::guard('nb_ocm_unlock');
        $count = LoginSecurity::unlockAll();
        Ui::redirect('beveiliging', 'success', sprintf('%d blokkade(s) opgeheven.', $count));
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
        Ui::guard('nb_ocm_pair');
        $code = isset($_POST['nb_ocm_code']) ? sanitize_text_field(wp_unslash((string) $_POST['nb_ocm_code'])) : '';
        try {
            $result = $this->pairing->pair($code);
            Ui::redirect('overzicht', 'success', sprintf('Gekoppeld aan %s als %s.', $result['panel_url'], $result['site_id']));
        } catch (\RuntimeException $e) {
            Ui::redirect('overzicht', 'error', $e->getMessage());
        }
    }

    public function handleUnpair(): void
    {
        Ui::guard('nb_ocm_unpair');
        if (($_POST['nb_ocm_confirm'] ?? '') !== 'ONTKOPPEL') {
            Ui::redirect('overzicht', 'error', 'Typ ONTKOPPEL om te bevestigen.');
        }
        $this->pairing->unpair();
        Ui::redirect('overzicht', 'success', 'De site is ontkoppeld. De sleutels zijn verwijderd.');
    }

    public function render(): void
    {
        if (!current_user_can(Ui::CAPABILITY)) {
            return;
        }
        $tabs = self::tabs();
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'overzicht'));
        if (!isset($tabs[$tab])) {
            $tab = 'overzicht';
        }
        $paired = Settings::isPaired();
        $chips = [[$paired ? 'Gekoppeld' : 'Niet gekoppeld', $paired ? 'ok' : 'warn']];
        if (SmtpModule::enabled()) {
            $active = SmtpModule::state()['active'];
            $chips[] = [$active ? 'SMTP actief' : 'SMTP niet actief', $active ? 'ok' : 'warn'];
        }
        $chips[] = ['v' . NB_OCM_VERSION, 'muted'];

        echo '<div class="wrap nbocm"><h1 class="screen-reader-text">NB OC Monitor</h1>';
        Ui::header('OC Monitor', 'Beheer, beveiliging en monitoring van deze site door Next Buzz', $tabs, $tab, $chips);
        Ui::notice(Ui::takeNotice());
        if (in_array($tab, ['smtp', 'maillog'], true)) {
            \NextBuzz\OcMonitor\Client\Smtp\Alerts\ProblemNotice::inline();
        }

        switch ($tab) {
            case 'beveiliging':
                $this->renderSecurity();
                break;
            case 'smtp':
                SmtpTab::render();
                break;
            case 'maillog':
                \NextBuzz\OcMonitor\Client\Smtp\Log\LogTab::render();
                break;
            case 'meldingen':
                \NextBuzz\OcMonitor\Client\Smtp\Alerts\AlertsTab::render();
                break;
            case 'logboek':
                $this->renderLog();
                break;
            default:
                $this->renderOverview($paired);
        }
        echo '</div>';
    }

    private function renderOverview(bool $paired): void
    {
        $summary = Settings::summary();
        echo '<div class="nbocm-grid">';

        Ui::cardOpen('Koppeling', $paired ? 'Deze site is gekoppeld aan het panel van Next Buzz.' : 'Deze site is nog niet gekoppeld.');
        $rows = [['Status', $paired ? '<span class="nbocm-pill is-ok">Gekoppeld</span>' : '<span class="nbocm-pill is-warn">Niet gekoppeld</span>']];
        if ($paired) {
            $rows[] = ['Panel', esc_html((string) $summary['panel_url'])];
            $rows[] = ['Site-id', '<code>' . esc_html((string) $summary['site_id']) . '</code>'];
            $rows[] = ['Gekoppeld op', esc_html($summary['paired_at'] ? wp_date('j F Y H:i', (int) $summary['paired_at']) : '–')];
            $rows[] = ['Panel-sleutel(s)', '<code>' . esc_html(implode(', ', (array) $summary['panel_key_ids'])) . '</code>'];
            $rows[] = ['Site-sleutel', '<code>' . esc_html((string) $summary['site_key_id']) . '</code>' . ($summary['site_key_in_wp_config'] ? ' <span class="nbocm-muted">(uit wp-config.php)</span>' : '')];
            $rows[] = ['Scopes', esc_html(implode(', ', (array) $summary['scopes']))];
        }
        $rows[] = ['Plugin-versie', esc_html(NB_OCM_VERSION)];
        Ui::definitions($rows);
        echo '<p class="nbocm-note">Noodstop: zet <code>define(\'NB_OCM_DISABLED\', true);</code> in wp-config.php om de koppeling direct uit te schakelen.</p>';
        Ui::cardClose();

        if (!$paired) {
            Ui::cardOpen('Koppelen', 'Maak in het panel een site aan en plak hier de koppelcode (15 minuten geldig).');
            Ui::formOpen('nb_ocm_pair');
            echo '<textarea name="nb_ocm_code" rows="5" required autocomplete="off" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace"></textarea>';
            echo '<div class="nbocm-actions">';
            Ui::button('Koppelen');
            echo '</div></form>';
            Ui::cardClose();
        } else {
            Ui::cardOpen('Wat het panel mag', 'Alle opdrachten zijn ondertekend en komen in het logboek.');
            echo '<p>Het panel kan de site uitlezen, het wijzigingslog ophalen, plugins, thema\'s, vertalingen en WordPress bijwerken (alleen wat WordPress zelf aanbiedt), deze plugin bijwerken naar een ondertekende release van Next Buzz, schakelaars en modules aan- of uitzetten, en beheerders blokkeren, uitloggen of een wachtwoordreset laten doen.</p>';
            echo '<p class="nbocm-muted">Het panel kan geen gebruikers aanmaken, geen wachtwoorden kiezen, geen SMTP-wachtwoorden zien en geen eigen code sturen.</p>';
            Ui::cardClose();
        }

        Ui::cardOpen('Modules', 'Extra onderdelen die Next Buzz vanuit het panel aanzet.');
        if (SmtpModule::enabled()) {
            $state = SmtpModule::state();
            Ui::definitions([[
                'SMTP',
                ($state['active'] ? '<span class="nbocm-pill is-ok">Actief</span>' : '<span class="nbocm-pill is-warn">Aan, nog niet actief</span>')
                . ($state['reason'] ? '<br><small class="nbocm-muted">' . esc_html($state['reason']) . '</small>' : '')
                . '<br><a href="' . esc_url(Ui::url('smtp')) . '">Naar de SMTP-instellingen →</a>',
            ]]);
        } else {
            Ui::definitions([['SMTP', '<span class="nbocm-pill">Uit</span> <small class="nbocm-muted">Next Buzz zet dit aan in het panel.</small>']]);
        }
        Ui::cardClose();

        if ($paired) {
            Ui::cardOpen('Ontkoppelen', 'Verwijdert de sleutels. Het panel kan daarna niets meer op deze site uitvoeren.', 'is-danger');
            Ui::formOpen('nb_ocm_unpair');
            Ui::field('Bevestiging', '<input type="text" id="nbocm-confirm" name="nb_ocm_confirm" autocomplete="off" placeholder="ONTKOPPEL">', 'Typ <strong>ONTKOPPEL</strong> ter bevestiging.', 'nbocm-confirm');
            echo '<div class="nbocm-actions">';
            Ui::button('Ontkoppelen', 'danger');
            echo '</div></form>';
            Ui::cardClose();
        }
        echo '</div>';
    }

    private function renderSecurity(): void
    {
        $c = LoginSecurity::config();
        $locked = LoginSecurity::activeLockouts(time());

        Ui::cardOpen('Inlogbeveiliging', 'Inloggen via wp-login.php, de blokeditor en de WordPress-app met een applicatiewachtwoord blijven gewoon werken.');
        Ui::formOpen('nb_ocm_login_security');
        echo '<div class="nbocm-field"><span class="nbocm-label">Blokkeren na mislukte pogingen</span><div>';
        Ui::toggle('nb_ocm_lockout', (bool) $c['lockout'], 'Blokkeer een IP-adres na te veel mislukte pogingen', 'Na drie blokkades binnen een dag duurt de blokkade 24 uur. Achter Cloudflare of een proxy op de server wordt het echte IP-adres gebruikt.');
        printf(
            '<div class="nbocm-inline">Na <input type="number" class="is-small" name="nb_ocm_max_attempts" value="%d" min="3" max="50"> pogingen binnen <input type="number" class="is-small" name="nb_ocm_window_minutes" value="%d" min="1" max="1440"> minuten, voor <input type="number" class="is-small" name="nb_ocm_lockout_minutes" value="%d" min="1" max="1440"> minuten.</div>',
            (int) $c['max_attempts'],
            (int) $c['window_minutes'],
            (int) $c['lockout_minutes']
        );
        echo '</div></div>';

        Ui::field(
            'Nooit blokkeren',
            sprintf('<textarea id="nbocm-allowlist" name="nb_ocm_allowlist" rows="3" placeholder="203.0.113.10&#10;198.51.100.0/24" style="font-family:ui-monospace,Consolas,monospace">%s</textarea>', esc_textarea(implode("\n", $c['allowlist']))),
            'IP-adressen of ranges (CIDR), één per regel. Bijvoorbeeld het kantoor. Jouw huidige IP-adres: <code>' . esc_html(ClientIp::resolve($_SERVER)) . '</code>',
            'nbocm-allowlist'
        );

        $options = '';
        foreach (LoginSecurity::XMLRPC_MODES as $mode => $label) {
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($mode), selected($c['xmlrpc'], $mode, false), esc_html($label));
        }
        Ui::field('XML-RPC-login', '<select id="nbocm-xmlrpc" name="nb_ocm_xmlrpc">' . $options . '</select>', 'Brute-force-aanvallen lopen vaak via xmlrpc.php. Uit = geen inloggen via XML-RPC, geen system.multicall en geen pingbacks. Jetpack heeft XML-RPC nodig.', 'nbocm-xmlrpc');

        $options = '';
        foreach (TwoFactor::MODES as $mode => $label) {
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($mode), selected(TwoFactor::mode(), $mode, false), esc_html($label));
        }
        Ui::field(
            'Tweestapsverificatie afdwingen',
            '<select id="nbocm-2fa" name="nb_ocm_two_factor">' . $options . '</select>',
            'Wie al een 2FA-plugin gebruikt (Two Factor, Wordfence, WP 2FA) merkt niets. Anderen krijgen na het wachtwoord een code per e-mail; controleer dus of de site mail kan versturen. Noodstop: <code>define(\'NB_OCM_DISABLE_2FA\', true);</code> in wp-config.php.'
            . (TwoFactor::disabledByConstant() ? ' <strong>Staat nu uit via NB_OCM_DISABLE_2FA.</strong>' : ''),
            'nbocm-2fa'
        );

        echo '<div class="nbocm-field"><span class="nbocm-label">Overig</span><div>';
        Ui::toggle('nb_ocm_hide_users', (bool) $c['hide_users'], 'Gebruikersnamen afschermen', 'Voor bezoekers die niet zijn ingelogd: geen gebruikerslijst via de REST API (/wp-json/wp/v2/users), geen ?author=1 en geen gebruikers in de sitemap.');
        Ui::toggle('nb_ocm_panel_login', PanelLogin::enabled(), 'Inloggen vanuit het panel', 'Beheerders van Next Buzz mogen vanuit het panel met een eenmalige link (60 seconden geldig) inloggen. Elke inlog komt in het logboek. Het panel kan deze instelling niet zelf aanzetten.');
        echo '</div></div>';

        echo '<div class="nbocm-actions">';
        Ui::button('Inlogbeveiliging opslaan');
        echo '</div></form>';
        Ui::cardClose();

        Ui::cardOpen('Nu geblokkeerd', $locked === [] ? 'Er zijn op dit moment geen IP-adressen geblokkeerd.' : sprintf('%d IP-range(s) kunnen tijdelijk niet inloggen.', count($locked)));
        if ($locked !== []) {
            echo '<div class="nbocm-table-wrap"><table class="nbocm-table"><thead><tr><th>IP-range</th><th>Tot</th><th>Blokkades vandaag</th></tr></thead><tbody>';
            foreach (array_slice($locked, 0, 50) as $entry) {
                printf('<tr><td><code>%s</code></td><td>%s</td><td>%d</td></tr>', esc_html((string) $entry['ip']), esc_html(wp_date('j M Y H:i', (int) $entry['until'])), (int) $entry['locks']);
            }
            echo '</tbody></table></div>';
            Ui::formOpen('nb_ocm_unlock');
            echo '<div class="nbocm-actions" style="margin-top:28px">';
            Ui::button(sprintf('Alle %d blokkades opheffen', count($locked)), 'secondary');
            echo '</div></form>';
        }
        Ui::cardClose();
    }

    private function renderLog(): void
    {
        Ui::cardOpen('Recente wijzigingen', 'De laatste 25 wijzigingen op deze site, door het panel, door gebruikers of van buitenaf.');
        echo '<div class="nbocm-table-wrap"><table class="nbocm-table"><thead><tr><th>#</th><th>Tijd</th><th>Type</th><th>Object</th><th>Van</th><th>Naar</th><th>Resultaat</th><th>Bron</th><th>Gebruiker</th></tr></thead><tbody>';
        $latest = $this->events->latest(25);
        if ($latest === []) {
            echo '<tr><td colspan="9" class="nbocm-empty">Nog geen wijzigingen vastgelegd.</td></tr>';
        }
        foreach ($latest as $event) {
            $ok = (string) $event['result'] === 'success';
            printf(
                '<tr><td>%d</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td><span class="nbocm-pill is-%s">%s</span></td><td>%s</td><td>%s</td></tr>',
                (int) $event['seq'],
                esc_html(wp_date('j M H:i', (int) $event['time'])),
                esc_html((string) $event['type']),
                esc_html((string) $event['object']),
                esc_html((string) ($event['from'] ?? '')),
                esc_html((string) ($event['to'] ?? '')),
                $ok ? 'ok' : 'crit',
                esc_html($ok ? 'gelukt' : (string) $event['result']),
                esc_html((string) $event['source']),
                esc_html((string) $event['user_login'])
            );
        }
        echo '</tbody></table></div>';
        Ui::cardClose();

        $failures = OptionFailureLog::read();
        Ui::cardOpen('Afgewezen verzoeken', 'Steekproef: hoogstens één registratie per minuut, zodat een aanvaller geen schrijfacties kan afdwingen.');
        echo '<div class="nbocm-table-wrap"><table class="nbocm-table"><thead><tr><th>Tijd</th><th>Reden</th><th>IP</th></tr></thead><tbody>';
        if ($failures['recent'] === []) {
            echo '<tr><td colspan="3" class="nbocm-empty">Geen.</td></tr>';
        }
        foreach ($failures['recent'] as $failure) {
            printf('<tr><td>%s</td><td>%s</td><td><code>%s</code></td></tr>', esc_html(wp_date('j M Y H:i', (int) $failure['time'])), esc_html((string) $failure['reason']), esc_html((string) $failure['ip']));
        }
        echo '</tbody></table></div>';
        Ui::cardClose();
    }

    /** Schild als menu-icoon (data-URI; WordPress kleurt het in). */
    private static function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5 3 4.2v5.1c0 4.3 2.9 8.1 7 9.2 4.1-1.1 7-4.9 7-9.2V4.2L10 1.5zm-1.2 12.2-3.2-3.2 1.3-1.3 1.9 1.9 4.4-4.4 1.3 1.3-5.7 5.7z"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
