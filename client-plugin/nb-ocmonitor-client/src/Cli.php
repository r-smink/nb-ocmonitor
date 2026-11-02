<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Events\EventStore;

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
