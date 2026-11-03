<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Welke groepen commands het panel op deze site mag uitvoeren. Sinds 0.3.0 staan lezen, het
 * wijzigingslog en updates altijd aan: een beheerder van de site kan updates vanuit het panel
 * niet meer uitzetten. Wie de koppeling helemaal wil stoppen, ontkoppelt of zet NB_OCM_DISABLED.
 *
 * Sinds 0.4.0 ook gebruikersbeheer (users). Nieuwe groepen komen hier bij.
 */
final class Scopes
{
    public const REQUIRED = ['read', 'events', 'updates', 'users'];

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
