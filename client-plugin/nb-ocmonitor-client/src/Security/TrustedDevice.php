<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Security;

/**
 * "Dit apparaat 30 dagen vertrouwen" na een geslaagde 2FA-code. De cookie bevat de vervaldatum en
 * een HMAC over gebruiker, vervaldatum en een stukje van de wachtwoord-hash: na een nieuw wachtwoord
 * vervallen alle vertrouwde apparaten. Zonder WordPress, voor de tests.
 */
final class TrustedDevice
{
    public const DAYS = 30;

    public static function make(int $userId, string $passwordHash, int $expires, string $secret): string
    {
        return $expires . '.' . self::mac($userId, $passwordHash, $expires, $secret);
    }

    public static function valid(string $cookie, int $userId, string $passwordHash, int $now, string $secret): bool
    {
        $parts = explode('.', $cookie, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] < $now) {
            return false;
        }

        return hash_equals(self::mac($userId, $passwordHash, (int) $parts[0], $secret), $parts[1]);
    }

    private static function mac(int $userId, string $passwordHash, int $expires, string $secret): string
    {
        return hash_hmac('sha256', $userId . '|' . $expires . '|' . substr($passwordHash, 8, 8), $secret);
    }
}
