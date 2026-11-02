<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Welke groepen commands het panel op deze site mag uitvoeren. "read" en "events" zijn altijd aan;
 * de optionele scopes zet een beheerder van de site zelf aan of uit (WP-admin of WP-CLI).
 */
final class Scopes
{
    public const REQUIRED = ['read', 'events'];

    /** @var array<string, string> scope => uitleg voor de beheerder */
    public const OPTIONAL = [
        'updates' => 'Plugins, thema\'s, vertalingen en WordPress bijwerken. Alleen updates die WordPress zelf aanbiedt; het panel kan geen eigen pakketten sturen.',
    ];

    /** Standaard voor nieuwe koppelingen. */
    public const DEFAULT = ['read', 'events', 'updates'];

    /**
     * Verplichte scopes plus de bekende optionele scopes uit $scopes, in vaste volgorde.
     *
     * @param mixed[] $scopes
     * @return string[]
     */
    public static function normalize(array $scopes): array
    {
        $result = self::REQUIRED;
        foreach (array_keys(self::OPTIONAL) as $scope) {
            if (in_array($scope, $scopes, true)) {
                $result[] = $scope;
            }
        }

        return $result;
    }
}
