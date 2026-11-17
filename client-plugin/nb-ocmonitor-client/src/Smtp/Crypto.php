<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

/**
 * Versleuteling van SMTP-wachtwoorden in de database (libsodium secretbox, XSalsa20-Poly1305).
 * De sleutel staat in NB_OCM_SMTP_KEY (wp-config.php, base64) of anders in een eigen optie, los
 * van de koppelsleutels: ontkoppelen of sleutelrotatie mag een opgeslagen wachtwoord niet breken.
 */
final class Crypto
{
    public const KEY_OPTION = 'nb_ocm_smtp_key';
    private const PREFIX = 'nbenc1:';

    public static function encrypt(string $plain): string
    {
        return $plain === '' ? '' : self::encryptWith(self::key(), $plain);
    }

    /** Geeft null als de waarde niet te ontsleutelen is (andere sleutel, beschadigd). */
    public static function decrypt(string $stored): ?string
    {
        if ($stored === '') {
            return '';
        }
        $key = self::key(false);

        return $key === null ? null : self::decryptWith($key, $stored);
    }

    public static function encryptWith(string $key, string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public static function decryptWith(string $key, string $stored): ?string
    {
        if (strpos($stored, self::PREFIX) !== 0) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );

        return $plain === false ? null : $plain;
    }

    private static function key(bool $create = true): ?string
    {
        if (defined('NB_OCM_SMTP_KEY')) {
            $key = base64_decode((string) constant('NB_OCM_SMTP_KEY'), true);

            return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null;
        }
        $stored = get_option(self::KEY_OPTION);
        $key = is_string($stored) ? base64_decode($stored, true) : false;
        if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $key;
        }
        if (!$create) {
            return null;
        }
        $key = sodium_crypto_secretbox_keygen();
        update_option(self::KEY_OPTION, base64_encode($key), false);

        return $key;
    }
}
