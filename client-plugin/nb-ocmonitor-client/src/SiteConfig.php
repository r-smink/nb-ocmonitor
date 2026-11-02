<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Protocol\KeyPair;

/**
 * Koppelgegevens van deze site. Alleen aanwezig als de site gekoppeld is.
 */
final class SiteConfig
{
    public string $siteId;
    public string $host;
    public string $panelUrl;
    /** @var string[] ruwe publieke panel-sleutels (huidige + eventueel vorige tijdens rotatie) */
    public array $panelPublicKeys;
    public KeyPair $siteKeys;
    /** @var string[] */
    public array $scopes;

    /**
     * @param string[] $panelPublicKeys
     * @param string[] $scopes
     */
    public function __construct(string $siteId, string $host, string $panelUrl, array $panelPublicKeys, KeyPair $siteKeys, array $scopes)
    {
        $this->siteId = $siteId;
        $this->host = $host;
        $this->panelUrl = $panelUrl;
        $this->panelPublicKeys = $panelPublicKeys;
        $this->siteKeys = $siteKeys;
        $this->scopes = $scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
