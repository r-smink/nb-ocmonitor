<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;
use NextBuzz\OcMonitor\Client\OptionFailureLog;
use NextBuzz\OcMonitor\Client\PairingService;
use NextBuzz\OcMonitor\Client\Scopes;
use NextBuzz\OcMonitor\Client\Settings;

/**
 * Extra > NB OC Monitor: status, koppelen en ontkoppelen, toegestane acties, recente events en afgewezen verzoeken.
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
        add_action('admin_post_nb_ocm_scopes', [$this, 'handleScopes']);
    }

    /** Scopes kunnen alleen hier (of via WP-CLI) veranderen, nooit via het panel. */
    public function handleScopes(): void
    {
        $this->guard('nb_ocm_scopes');
        $wanted = [];
        foreach (array_keys(Scopes::OPTIONAL) as $scope) {
            if (!empty($_POST['nb_ocm_scope'][$scope])) {
                $wanted[] = $scope;
            }
        }
        $change = Settings::setScopes($wanted);
        if ($change === null) {
            $this->redirect('error', 'De site is niet gekoppeld.');
        }
        if ($change['from'] !== $change['to']) {
            $this->recorder->record('ocm.scopes_changed', 'scopes', implode(',', $change['from']), implode(',', $change['to']));
        }
        $this->redirect('success', 'Toegestane acties opgeslagen.');
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
            $active = (array) $summary['scopes'];
            echo '<h2>Toegestane acties vanuit het panel</h2><p>Lezen en het wijzigingslog staan altijd aan. Het panel kan deze instelling niet zelf veranderen.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('nb_ocm_scopes');
            echo '<input type="hidden" name="action" value="nb_ocm_scopes">';
            foreach (Scopes::OPTIONAL as $scope => $description) {
                printf(
                    '<p><label><input type="checkbox" name="nb_ocm_scope[%1$s]" value="1"%2$s> <code>%1$s</code> — %3$s</label></p>',
                    esc_attr($scope),
                    in_array($scope, $active, true) ? ' checked' : '',
                    esc_html($description)
                );
            }
            submit_button('Opslaan', 'secondary');
            echo '</form>';

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
