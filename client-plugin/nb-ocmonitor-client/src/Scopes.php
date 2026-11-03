<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Welke groepen commands het panel op deze site mag uitvoeren. Sinds 0.3.0 staan lezen, het
 * wijzigingslog en updates altijd aan: een beheerder van de site kan updates vanuit het panel
 * niet meer uitzetten. Wie de koppeling helemaal wil stoppen, ontkoppelt of zet NB_OCM_DISABLED.
 *
 * Sinds 0.4.0 ook gebruikersbeheer (users), sinds 0.6.0 database opschonen (maintenance), sinds 0.7.0 licenties activeren (licenses).
 */
final class Scopes
{
    public const REQUIRED = ['read', 'events', 'updates', 'users', 'maintenance', 'licenses'];

    /** Standaard voor nieuwe koppelingen. */
    public const DEFAULT = self::REQUIRED;

    /**
     * De vaste scopes; onbekende waarden uit oude configuraties vallen weg.
     *
     * @param mixed[] $scopes
     * @return string[]
     */
    public static function normalize(array $scopes): array
    {
        return self::REQUIRED;
    }
}
