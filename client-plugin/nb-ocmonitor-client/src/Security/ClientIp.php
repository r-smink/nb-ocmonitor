<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Security;

/**
 * Bepaalt het IP-adres van de bezoeker zonder dat die het kan vervalsen. Proxy-headers worden
 * alleen geloofd als het verzoek echt van een proxy komt: Cloudflare (bekende ranges) of een
 * lokale/private proxy op dezelfde server of in hetzelfde netwerk. Zonder WordPress, voor tests.
 */
final class ClientIp
{
    /** https://www.cloudflare.com/ips/ */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * @param array<string, mixed> $server $_SERVER
     */
    public static function resolve(array $server): string
    {
        $remote = self::valid($server['REMOTE_ADDR'] ?? null) ?? '0.0.0.0';

        if (self::inAny($remote, self::CLOUDFLARE)) {
            return self::valid($server['HTTP_CF_CONNECTING_IP'] ?? null) ?? $remote;
        }
        if (self::isPrivate($remote) && is_string($server['HTTP_X_FORWARDED_FOR'] ?? null)) {
            // Van rechts naar links: het eerste publieke adres is wat onze eigen proxy zag.
            $hops = array_reverse(array_map('trim', explode(',', $server['HTTP_X_FORWARDED_FOR'])));
            foreach ($hops as $hop) {
                $ip = self::valid($hop);
                if ($ip !== null && !self::isPrivate($ip)) {
                    return $ip;
                }
            }
        }

        return $remote;
    }

    /**
     * @param string[] $ranges IP-adressen of CIDR-ranges, IPv4 en IPv6
     */
    public static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function inRange(string $ip, string $range): bool
    {
        $range = trim($range);
        $bits = null;
        if (strpos($range, '/') !== false) {
            [$range, $prefix] = explode('/', $range, 2);
            if (!ctype_digit($prefix)) {
                return false;
            }
            $bits = (int) $prefix;
        }
        $a = @inet_pton($ip);
        $b = @inet_pton($range);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $max = strlen($a) * 8;
        $bits = $bits === null ? $max : $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** Een geldige range voor de uitzonderingslijst. */
    public static function isValidRange(string $range): bool
    {
        $parts = explode('/', trim($range), 2);
        if (self::valid($parts[0]) === null) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        $max = strpos($parts[0], ':') !== false ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] <= $max;
    }

    /** Ingekort voor opslag en weergave (AVG): IPv4 /24, IPv6 /48. */
    public static function anonymize(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip) . '/24';
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '?';
        }

        return (string) inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    private static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** @param mixed $value */
    private static function valid($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }
}
