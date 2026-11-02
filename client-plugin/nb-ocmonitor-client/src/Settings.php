<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\KeyPair;

/**
 * Opslag van de koppeling in wp_options (autoload uit). De private site-sleutel staat in een
 * aparte optie, of in wp-config.php als NB_OCM_SITE_KEY gedefinieerd is (die gaat voor).
 */
final class Settings
{
    public const OPTION = 'nb_ocm_config';
    public const KEY_OPTION = 'nb_ocm_site_key';

    public static function load(): ?SiteConfig
    {
        $config = get_option(self::OPTION);
        $siteKey = self::siteKeyB64();
        if (!is_array($config) || empty($config['site_id']) || $siteKey === null) {
            return null;
        }

        try {
            $keys = KeyPair::fromSecretKeyB64($siteKey);
            $panelKeys = [];
            foreach ((array) ($config['panel_keys'] ?? []) as $encoded) {
                $raw = Base64Url::decode((string) $encoded);
                if (strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    $panelKeys[] = $raw;
                }
            }
            $host = self::host();
        } catch (\Throwable $e) {
            return null;
        }

        return new SiteConfig(
            (string) $config['site_id'],
            $host,
            (string) $config['panel_url'],
            $panelKeys,
            $keys,
            Scopes::normalize((array) ($config['scopes'] ?? Scopes::REQUIRED))
        );
    }

    public static function isPaired(): bool
    {
        $config = get_option(self::OPTION);

        return is_array($config) && !empty($config['site_id']);
    }

    /** @return array<string, mixed> voor weergave; bevat geen secrets */
    public static function summary(): array
    {
        $config = get_option(self::OPTION);
        if (!is_array($config)) {
            return [];
        }
        $panelKeyIds = [];
        foreach ((array) ($config['panel_keys'] ?? []) as $encoded) {
            try {
                $panelKeyIds[] = KeyPair::keyIdFor(Base64Url::decode((string) $encoded));
            } catch (\Throwable $e) {
                $panelKeyIds[] = '(ongeldig)';
            }
        }
        $siteKeyId = null;
        $siteKey = self::siteKeyB64();
        if ($siteKey !== null) {
            try {
                $siteKeyId = KeyPair::fromSecretKeyB64($siteKey)->keyId();
            } catch (\Throwable $e) {
                $siteKeyId = '(ongeldig)';
            }
        }

        return [
            'site_id' => $config['site_id'] ?? null,
            'panel_url' => $config['panel_url'] ?? null,
            'panel_key_ids' => $panelKeyIds,
            'site_key_id' => $siteKeyId,
            'site_key_in_wp_config' => defined('NB_OCM_SITE_KEY'),
            'scopes' => Scopes::normalize((array) ($config['scopes'] ?? Scopes::REQUIRED)),
            'paired_at' => $config['paired_at'] ?? null,
        ];
    }

    /**
     * @param string[] $panelKeysB64
     */
    public static function save(string $siteId, string $panelUrl, array $panelKeysB64, ?string $siteKeyB64, int $now): void
    {
        if ($siteKeyB64 !== null) {
            update_option(self::KEY_OPTION, $siteKeyB64, false);
        }
        update_option(self::OPTION, [
            'site_id' => $siteId,
            'panel_url' => $panelUrl,
            'panel_keys' => array_values($panelKeysB64),
            'scopes' => Scopes::DEFAULT,
            'paired_at' => $now,
        ], false);
    }

    /**
     * Zet de optionele scopes. Alleen vanuit WP-admin of WP-CLI, nooit via een panel-command.
     *
     * @param string[] $scopes
     * @return array{from: string[], to: string[]}|null null als de site niet gekoppeld is
     */
    public static function setScopes(array $scopes): ?array
    {
        $config = get_option(self::OPTION);
        if (!is_array($config) || empty($config['site_id'])) {
            return null;
        }
        $from = Scopes::normalize((array) ($config['scopes'] ?? Scopes::REQUIRED));
        $config['scopes'] = Scopes::normalize($scopes);
        update_option(self::OPTION, $config, false);

        return ['from' => $from, 'to' => $config['scopes']];
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
        delete_option(self::KEY_OPTION);
    }

    /** Host uit home_url(), genormaliseerd zoals in de handtekening. Nooit de Host-header. */
    public static function host(): string
    {
        $parts = wp_parse_url(home_url());
        $host = (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $host .= ':' . (int) $parts['port'];
        }

        return Canonical::host($host);
    }

    public static function siteKeyB64(): ?string
    {
        if (defined('NB_OCM_SITE_KEY')) {
            return (string) constant('NB_OCM_SITE_KEY');
        }
        $value = get_option(self::KEY_OPTION);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
