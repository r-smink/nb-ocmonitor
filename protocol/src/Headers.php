<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

final class Headers
{
    public const SITE = 'x-nbocm-site';
    public const KEY = 'x-nbocm-key';
    public const TS = 'x-nbocm-ts';
    public const NONCE = 'x-nbocm-nonce';
    public const SIG = 'x-nbocm-sig';

    /**
     * Maakt headernamen lowercase en vervangt '_' door '-' (WP_REST_Request levert namen als
     * "x_nbocm_sig"). Bij meerdere waarden telt alleen de eerste.
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, string>
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $normalized[str_replace('_', '-', strtolower((string) $name))] = (string) $value;
        }

        return $normalized;
    }
}
