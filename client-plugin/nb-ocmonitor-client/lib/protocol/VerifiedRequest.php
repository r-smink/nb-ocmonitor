<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Protocol;

final class VerifiedRequest
{
    public string $keyId;
    public int $timestamp;
    public string $nonce;

    public function __construct(string $keyId, int $timestamp, string $nonce)
    {
        $this->keyId = $keyId;
        $this->timestamp = $timestamp;
        $this->nonce = $nonce;
    }
}
