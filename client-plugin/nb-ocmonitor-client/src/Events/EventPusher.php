<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Events;

use NextBuzz\OcMonitor\Client\SiteConfig;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\Signer;

/**
 * Stuurt nieuwe events direct naar het panel, ondertekend met de site-sleutel (context NBOCM-v1-site).
 * Non-blocking: de bezoeker wacht er niet op. Mislukt de push, dan haalt het panel de events
 * later zelf op via events.pull. Het panel ontdubbelt op seq.
 */
final class EventPusher
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function push(SiteConfig $config, array $events): void
    {
        if ($events === []) {
            return;
        }

        $request = self::buildRequest($config, $events, time());
        if ($request === null) {
            return;
        }

        wp_remote_post($request['url'], [
            'body' => $request['body'],
            'headers' => $request['headers'],
            'timeout' => 3,
            'blocking' => false,
            'redirection' => 0,
            'sslverify' => true,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{url: string, body: string, headers: array<string, string>}|null
     */
    public static function buildRequest(SiteConfig $config, array $events, int $now): ?array
    {
        $panel = wp_parse_url($config->panelUrl);
        if (!is_array($panel) || ($panel['scheme'] ?? '') !== 'https' || empty($panel['host'])) {
            return null;
        }
        $host = $panel['host'] . (isset($panel['port']) ? ':' . (int) $panel['port'] : '');
        $path = rtrim((string) ($panel['path'] ?? ''), '/') . '/api/sites/' . $config->siteId . '/events';

        $body = (string) wp_json_encode(['site_id' => $config->siteId, 'events' => $events], JSON_UNESCAPED_SLASHES);
        try {
            $headers = (new Signer($config->siteKeys))->signRequest(Canonical::CTX_SITE, 'POST', $host, $path, $config->siteId, $body, $now);
        } catch (\Throwable $e) {
            return null;
        }
        $headers['content-type'] = 'application/json';

        return ['url' => 'https://' . $host . $path, 'body' => $body, 'headers' => $headers];
    }
}
