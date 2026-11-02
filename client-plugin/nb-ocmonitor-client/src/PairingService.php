<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\Pairing;
use NextBuzz\OcMonitor\Protocol\VerificationFailed;

/**
 * Koppelt de site aan het panel (docs/protocol.md §2). Alleen aan te roepen door een beheerder
 * (WP-admin met capability en nonce) of via WP-CLI.
 */
final class PairingService
{
    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    /**
     * @return array{site_id: string, panel_url: string, panel_key_id: string, site_key_id: string}
     * @throws \RuntimeException met een melding voor de beheerder
     */
    public function pair(string $code): array
    {
        if (Settings::isPaired()) {
            throw new \RuntimeException('Deze site is al gekoppeld. Ontkoppel eerst.');
        }

        $now = time();
        try {
            $parsed = Pairing::parseCode($code, $now);
        } catch (VerificationFailed $e) {
            throw new \RuntimeException($e->reason() === VerificationFailed::EXPIRED
                ? 'De koppelcode is verlopen. Maak een nieuwe aan in het panel.'
                : 'De koppelcode is ongeldig.');
        }

        $home = untrailingslashit(home_url());
        $allowHttp = defined('NB_OCM_ALLOW_HTTP') && NB_OCM_ALLOW_HTTP;
        if (wp_parse_url($home, PHP_URL_SCHEME) !== 'https' && !$allowHttp) {
            throw new \RuntimeException('De site moet via HTTPS bereikbaar zijn (home_url begint niet met https://).');
        }

        $keyFromConfig = defined('NB_OCM_SITE_KEY');
        $siteKeys = $keyFromConfig ? KeyPair::fromSecretKeyB64((string) constant('NB_OCM_SITE_KEY')) : KeyPair::generate();
        $proof = Pairing::proof($parsed['site_id'], $siteKeys, $home, $parsed['secret'], $now);

        $response = wp_remote_post($parsed['panel_url'] . '/api/pair', [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode($proof),
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('Panel niet bereikbaar: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($body) || ($body['ok'] ?? false) !== true || ($body['site_id'] ?? null) !== $parsed['site_id']) {
            throw new \RuntimeException(sprintf('Het panel weigerde de koppeling (HTTP %d).', $status));
        }

        Settings::save($parsed['site_id'], $parsed['panel_url'], [Base64Url::encode($parsed['panel_pk'])], $keyFromConfig ? null : $siteKeys->secretKeyB64(), $now);
        Cron::schedule();
        $this->recorder->record('ocm.paired', $parsed['site_id'], null, null, 'success', [
            'panel_url' => $parsed['panel_url'],
            'panel_key_id' => $parsed['panel_kid'],
            'site_key_id' => $siteKeys->keyId(),
        ]);

        return [
            'site_id' => $parsed['site_id'],
            'panel_url' => $parsed['panel_url'],
            'panel_key_id' => $parsed['panel_kid'],
            'site_key_id' => $siteKeys->keyId(),
        ];
    }

    public function unpair(): void
    {
        $siteId = (string) (Settings::summary()['site_id'] ?? '');
        Settings::clear();
        $this->recorder->record('ocm.unpaired', $siteId);
    }
}
