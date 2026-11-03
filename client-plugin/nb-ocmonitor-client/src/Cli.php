<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;
use NextBuzz\OcMonitor\Client\Security\ClientIp;
use NextBuzz\OcMonitor\Client\Security\LoginSecurity;

/**
 * Beheer van NB OC Monitor via WP-CLI.
 */
final class Cli
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

    /**
     * Koppelt deze site aan het panel.
     *
     * ## OPTIONS
     *
     * <code>
     * : De koppelcode uit het panel (begint met nbocm1.)
     *
     * @param string[] $args
     */
    public function pair(array $args): void
    {
        try {
            $result = $this->pairing->pair($args[0] ?? '');
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::success(sprintf('Gekoppeld aan %s als %s (site-sleutel %s).', $result['panel_url'], $result['site_id'], $result['site_key_id']));
    }

    /**
     * Ontkoppelt de site en verwijdert de sleutels.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Niet om bevestiging vragen.
     *
     * @param string[] $args
     * @param array<string, mixed> $assoc
     */
    public function unpair(array $args, array $assoc): void
    {
        \WP_CLI::confirm('De site ontkoppelen van het panel?', $assoc);
        $this->pairing->unpair();
        \WP_CLI::success('Ontkoppeld.');
    }

    /**
     * Toont de koppelstatus.
     */
    public function status(): void
    {
        $summary = Settings::summary();
        if ($summary === []) {
            \WP_CLI::log('Niet gekoppeld.');
            return;
        }
        foreach ($summary as $key => $value) {
            \WP_CLI::log(sprintf('%-22s %s', $key, is_array($value) ? implode(', ', $value) : var_export($value, true)));
        }
        \WP_CLI::log(sprintf('%-22s %d', 'last_event_seq', $this->events->lastSeq()));
    }

    /**
     * Toont of wijzigt de inlogbeveiliging.
     *
     * ## OPTIONS
     *
     * [--lockout=<on|off>]
     * : Blokkade na te veel mislukte pogingen.
     *
     * [--max-attempts=<n>]
     * : Pogingen voor een blokkade (3-50).
     *
     * [--lockout-minutes=<n>]
     * : Duur van een blokkade.
     *
     * [--xmlrpc=<auto|off|on>]
     * : XML-RPC-login.
     *
     * [--hide-users=<on|off>]
     * : Gebruikersnamen afschermen voor anonieme bezoekers.
     *
     * [--allow=<ip>]
     * : IP-adres of CIDR-range toevoegen aan de uitzonderingen.
     *
     * [--unlock]
     * : Alle blokkades opheffen.
     *
     * @subcommand login-security
     * @param string[] $args
     * @param array<string, mixed> $assoc
     */
    public function loginSecurity(array $args, array $assoc): void
    {
        if (isset($assoc['unlock'])) {
            \WP_CLI::log(sprintf('%d blokkade(s) opgeheven.', LoginSecurity::unlockAll()));
        }
        $config = LoginSecurity::config();
        $flag = static function (string $value): bool {
            return in_array(strtolower($value), ['on', 'aan', '1', 'true', 'yes'], true);
        };
        $map = ['lockout' => 'lockout', 'max-attempts' => 'max_attempts', 'lockout-minutes' => 'lockout_minutes', 'xmlrpc' => 'xmlrpc', 'hide-users' => 'hide_users'];
        $changed = false;
        foreach ($map as $option => $key) {
            if (isset($assoc[$option])) {
                $config[$key] = in_array($key, ['lockout', 'hide_users'], true) ? $flag((string) $assoc[$option]) : $assoc[$option];
                $changed = true;
            }
        }
        if (isset($assoc['allow'])) {
            if (!ClientIp::isValidRange((string) $assoc['allow'])) {
                \WP_CLI::error('Ongeldig IP-adres of range: ' . $assoc['allow']);
                return;
            }
            $config['allowlist'][] = (string) $assoc['allow'];
            $changed = true;
        }
        if ($changed) {
            $change = LoginSecurity::save($config);
            if ($change['from'] !== $change['to']) {
                $this->recorder->record('ocm.login_security_changed', 'login_security', null, null, 'success', ['via' => 'wp-cli']);
                $this->recorder->flush();
            }
        }
        foreach (LoginSecurity::summary() as $key => $value) {
            \WP_CLI::log(sprintf('%-16s %s', $key, is_bool($value) ? ($value ? 'aan' : 'uit') : (string) $value));
        }
    }

    /**
     * Toont de laatst vastgelegde wijzigingen.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Aantal events.
     * ---
     * default: 20
     * ---
     *
     * @param string[] $args
     * @param array<string, mixed> $assoc
     */
    public function events(array $args, array $assoc): void
    {
        $rows = array_map(static function (array $event): array {
            return [
                'seq' => $event['seq'],
                'time' => wp_date('Y-m-d H:i', (int) $event['time']),
                'type' => $event['type'],
                'object' => $event['object'],
                'from' => $event['from'],
                'to' => $event['to'],
                'result' => $event['result'],
                'source' => $event['source'],
                'user' => $event['user_login'],
            ];
        }, $this->events->latest(max(1, min(500, (int) ($assoc['limit'] ?? 20)))));
        \WP_CLI\Utils\format_items('table', $rows, ['seq', 'time', 'type', 'object', 'from', 'to', 'result', 'source', 'user']);
    }

    /**
     * Controleert op wijzigingen buiten WordPress om (FTP, deploy) en legt ze vast.
     *
     * @subcommand check-versions
     */
    public function checkVersions(): void
    {
        $count = $this->recorder->recordExternalChanges();
        $this->recorder->flush();
        \WP_CLI::success(sprintf('%d onverklaarde wijziging(en) vastgelegd.', $count));
    }
}
