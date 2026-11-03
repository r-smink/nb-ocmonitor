<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Backup;

/**
 * Versleuteling van backupbestanden met libsodium secretstream (XChaCha20-Poly1305), in blokken
 * van 1 MB. Elk blok is geauthenticeerd en het laatste blok draagt de FINAL-tag, dus een
 * gewijzigd of afgekapt bestand wordt geweigerd. De sleutel staat alleen op de site zelf.
 * Zonder WordPress, voor de tests.
 */
final class BackupCrypto
{
    private const CHUNK = 1048576;
    private const MAGIC = "NBOCMBK1";

    public static function generateKey(): string
    {
        return sodium_crypto_secretstream_xchacha20poly1305_keygen();
    }

    public static function encryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new \RuntimeException('Kan backupbestand niet openen');
        }
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($dst, self::MAGIC . $header);
            $chunk = (string) fread($src, self::CHUNK);
            while (true) {
                $next = feof($src) ? '' : (string) fread($src, self::CHUNK);
                $last = $next === '' && feof($src);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                );
                fwrite($dst, pack('N', strlen($cipher)) . $cipher);
                if ($last) {
                    break;
                }
                $chunk = $next;
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    public static function decryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new \RuntimeException('Kan backupbestand niet openen');
        }
        try {
            $magic = (string) fread($src, strlen(self::MAGIC));
            $header = (string) fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($magic !== self::MAGIC || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new \RuntimeException('Geen geldig backupbestand');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;
            while (!$final) {
                $len = (string) fread($src, 4);
                if (strlen($len) !== 4) {
                    throw new \RuntimeException('Backupbestand is afgekapt');
                }
                $size = unpack('N', $len)[1];
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > self::CHUNK + 1024) {
                    throw new \RuntimeException('Backupbestand is beschadigd');
                }
                $cipher = (string) fread($src, $size);
                $result = strlen($cipher) === $size ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher) : false;
                if ($result === false) {
                    throw new \RuntimeException('Backupbestand is gewijzigd of met een andere sleutel gemaakt');
                }
                [$plain, $tag] = $result;
                fwrite($dst, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (fread($src, 1) !== '') {
                throw new \RuntimeException('Onverwachte gegevens na het einde van de backup');
            }
        } catch (\Throwable $e) {
            fclose($dst);
            $dst = null;
            @unlink($out);
            throw $e;
        } finally {
            fclose($src);
            if ($dst !== null) {
                fclose($dst);
            }
        }
    }
}
