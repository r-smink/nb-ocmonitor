<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Een verwachte fout in een command (ongeldige argumenten e.d.). Gaat ondertekend terug naar het panel.
 */
final class CommandError extends \RuntimeException
{
    private string $errorCode;
    private int $status;

    public function __construct(string $errorCode, string $message = '', int $status = 400)
    {
        $this->errorCode = $errorCode;
        $this->status = $status;
        parent::__construct($message === '' ? $errorCode : $message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
