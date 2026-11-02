<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

final class EndpointResult
{
    public int $status;
    /** @var array<string, string> */
    public array $headers;
    /** Exacte bytes die verstuurd moeten worden (de handtekening dekt deze body). */
    public string $body;
    /** True als het verzoek niet geverifieerd is: stuur dan een generieke 404 zonder details. */
    public bool $notFound;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(int $status, array $headers, string $body, bool $notFound)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->notFound = $notFound;
    }

    public static function notFound(): self
    {
        return new self(404, [], '', true);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function signed(int $status, array $headers, string $body): self
    {
        return new self($status, $headers, $body, false);
    }
}
