<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

/**
 * De beslislogica van de meldingen, zonder WordPress (tijd wordt meegegeven):
 * welk resultaat geeft welke melding, wanneer wordt een melding ingehouden (throttling) en
 * wanneer volgt de samenvatting van wat er is ingehouden.
 *
 * Per signatuur (type + diagnosecode) gaat er hooguit één melding per venster uit. Wat daarna
 * binnen het venster gebeurt, wordt geteld en bij de volgende melding meegestuurd; staat er aan
 * het eind van het venster nog iets open, dan stuurt de flush één samenvatting.
 *
 * Status (optie nb_ocm_smtp_alert_state):
 *   throttle: sig => {sent: int, count: int, since: int, last: array} (count = ingehouden sinds sent)
 *   failing: aantal mislukte of via backup verstuurde mails sinds de laatste gewone geslaagde
 *   failing_since: tijd van de eerste daarvan
 *   alerted: is er sindsdien een failed/backup_used-melding naar het panel gegaan
 *   notified: is er sindsdien een failed/backup_used-melding naar e-mail/webhook gegaan
 *
 * Een "emit" is: {type, result, suppressed, since, failures, digest, panel, notify}
 * (panel: event naar het panel; notify: naar e-mail/webhooks).
 */
final class AlertState
{
    public const OPTION = 'nb_ocm_smtp_alert_state';
    private const PRUNE_AFTER = 86400;

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return ['throttle' => [], 'failing' => 0, 'failing_since' => 0, 'alerted' => false, 'notified' => false];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $result zie docs/smtp-module.md §3
     * @param array<string, bool> $toggles type => melding aan (e-mail/webhook)
     * @return array{state: array<string, mixed>, emit: list<array<string, mixed>>, schedule: list<array{sig: string, at: int}>}
     */
    public static function apply(array $state, array $result, int $now, int $windowMinutes, array $toggles): array
    {
        $state = self::normalize($state);
        $out = ['state' => $state, 'emit' => [], 'schedule' => []];
        if (!empty($result['is_test'])) {
            return $out;
        }
        $type = self::typeOf($result);

        if ($type === null) {
            // Gewone geslaagde mail: herstel melden als er eerder een melding uitging.
            if ($state['failing'] > 0 && ($state['alerted'] || $state['notified'])) {
                $out['emit'][] = [
                    'type' => 'recovered',
                    'result' => $result,
                    'suppressed' => 0,
                    'since' => $state['failing_since'],
                    'failures' => $state['failing'],
                    'digest' => false,
                    'panel' => $state['alerted'],
                    'notify' => $state['notified'] && !empty($toggles['recovered']),
                ];
            }
            if ($state['failing'] > 0) {
                // Nieuw incident begint straks met een schone lei; ingehouden meldingen zijn opgenomen in het herstel.
                $state = self::empty();
            }
            $out['state'] = self::prune($state, $now);

            return $out;
        }

        if ($state['failing'] === 0) {
            $state['failing_since'] = $now;
        }
        $state['failing']++;

        $sig = self::signature($type, (string) ($result['diagnose_code'] ?? ''));
        $entry = $state['throttle'][$sig] ?? null;
        $window = max(1, $windowMinutes) * 60;

        if ($entry === null || $now - (int) $entry['sent'] >= $window) {
            $pending = $entry !== null ? (int) $entry['count'] : 0;
            $out['emit'][] = [
                'type' => $type,
                'result' => $result,
                'suppressed' => $pending,
                'since' => $pending > 0 ? (int) $entry['since'] : null,
                'failures' => $state['failing'],
                'digest' => false,
                'panel' => true,
                'notify' => !empty($toggles[$type]),
            ];
            $state['throttle'][$sig] = ['sent' => $now, 'count' => 0, 'since' => 0, 'last' => []];
            $state['alerted'] = true;
            $state['notified'] = $state['notified'] || !empty($toggles[$type]);
        } else {
            if ((int) $entry['count'] === 0) {
                $entry['since'] = $now;
                $out['schedule'][] = ['sig' => $sig, 'at' => (int) $entry['sent'] + $window];
            }
            $entry['count'] = (int) $entry['count'] + 1;
            $entry['last'] = self::slim($result);
            $state['throttle'][$sig] = $entry;
        }
        $out['state'] = self::prune($state, $now);

        return $out;
    }

    /**
     * Einde van een venster: één samenvatting van de ingehouden meldingen.
     *
     * @param array<string, mixed> $state
     * @param array<string, bool> $toggles
     * @return array{state: array<string, mixed>, emit: list<array<string, mixed>>, schedule: list<array{sig: string, at: int}>}
     */
    public static function flush(array $state, string $sig, int $now, int $windowMinutes, array $toggles): array
    {
        $state = self::normalize($state);
        $out = ['state' => $state, 'emit' => [], 'schedule' => []];
        $entry = $state['throttle'][$sig] ?? null;
        if ($entry === null || (int) $entry['count'] === 0) {
            return $out;
        }
        $window = max(1, $windowMinutes) * 60;
        if ($now - (int) $entry['sent'] < $window) {
            // Te vroeg (bijv. venster verlengd): later opnieuw proberen.
            $out['schedule'][] = ['sig' => $sig, 'at' => (int) $entry['sent'] + $window];

            return $out;
        }
        $type = (string) strstr($sig, ':', true);
        $out['emit'][] = [
            'type' => $type,
            'result' => (array) $entry['last'],
            'suppressed' => (int) $entry['count'],
            'since' => (int) $entry['since'],
            'failures' => $state['failing'],
            'digest' => true,
            'panel' => true,
            'notify' => !empty($toggles[$type]),
        ];
        $state['throttle'][$sig] = ['sent' => $now, 'count' => 0, 'since' => 0, 'last' => []];
        $state['alerted'] = true;
        $state['notified'] = $state['notified'] || !empty($toggles[$type]);
        $out['state'] = $state;

        return $out;
    }

    /** @param array<string, mixed> $result */
    public static function typeOf(array $result): ?string
    {
        if (($result['status'] ?? '') === 'failed') {
            return 'failed';
        }
        if (($result['status'] ?? '') === 'sent' && ($result['connection'] ?? '') === 'backup') {
            return 'backup_used';
        }

        return null;
    }

    public static function signature(string $type, string $code): string
    {
        return $type . ':' . ($code !== '' ? $code : 'unknown');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function normalize(array $state): array
    {
        $state += self::empty();
        $state['throttle'] = is_array($state['throttle']) ? $state['throttle'] : [];
        $state['failing'] = (int) $state['failing'];
        $state['failing_since'] = (int) $state['failing_since'];
        $state['alerted'] = (bool) $state['alerted'];
        $state['notified'] = (bool) $state['notified'];

        return $state;
    }

    /**
     * Alleen de velden die een melding nodig heeft, ingekort (de status staat in een optie).
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function slim(array $result): array
    {
        return [
            'log_id' => isset($result['log_id']) ? (int) $result['log_id'] : null,
            'status' => (string) ($result['status'] ?? ''),
            'connection' => (string) ($result['connection'] ?? ''),
            'to' => array_slice(array_map('strval', (array) ($result['to'] ?? [])), 0, 5),
            'subject' => mb_substr((string) ($result['subject'] ?? ''), 0, 200),
            'error' => mb_substr((string) ($result['error'] ?? ''), 0, 500),
            'backup_error' => mb_substr((string) ($result['backup_error'] ?? ''), 0, 500),
            'diagnose_code' => (string) ($result['diagnose_code'] ?? ''),
            'initiator' => mb_substr((string) ($result['initiator'] ?? ''), 0, 100),
            'time' => (int) ($result['time'] ?? 0),
        ];
    }

    /**
     * Oude vensters zonder ingehouden meldingen opruimen.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function prune(array $state, int $now): array
    {
        foreach ($state['throttle'] as $sig => $entry) {
            if ((int) ($entry['count'] ?? 0) === 0 && $now - (int) ($entry['sent'] ?? 0) > self::PRUNE_AFTER) {
                unset($state['throttle'][$sig]);
            }
        }

        return $state;
    }
}
