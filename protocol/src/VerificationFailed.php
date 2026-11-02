<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

/**
 * Elke afgewezen verificatie. De reden is alleen voor lokale logging bedoeld;
 * naar buiten (HTTP-antwoord) mag nooit meer dan een generieke 404 lekken.
 */
final class VerificationFailed extends \RuntimeException
{
    public const MALFORMED = 'malformed';
    public const UNKNOWN_KEY = 'unknown_key';
    public const STALE_TIMESTAMP = 'stale_timestamp';
    public const BAD_SIGNATURE = 'bad_signature';
    public const REPLAY = 'replay';
    public const SITE_MISMATCH = 'site_mismatch';
    public const WRONG_AUDIENCE = 'wrong_audience';
    public const EXPIRED = 'expired';

    private string $reason;

    public function __construct(string $reason, string $detail = '')
    {
        $this->reason = $reason;
        parent::__construct($detail === '' ? $reason : $reason . ': ' . $detail);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
