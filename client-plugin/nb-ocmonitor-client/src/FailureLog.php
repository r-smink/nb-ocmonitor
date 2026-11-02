<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

interface FailureLog
{
    /** Registreert een afgewezen verzoek. Nooit headers, body of andere secrets loggen. */
    public function record(string $reason, string $ip, int $now): void;
}
