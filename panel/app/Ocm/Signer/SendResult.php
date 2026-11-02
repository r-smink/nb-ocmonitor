<?php

namespace App\Ocm\Signer;

final class SendResult
{
    /**
     * @param array<string, mixed> $result
     */
    private function __construct(
        public readonly bool $ok,
        public readonly bool $unreachable,
        public readonly array $result,
        public readonly ?string $error,
        public readonly ?int $httpStatus,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function ok(array $result, int $status): self
    {
        return new self(true, false, $result, null, $status);
    }

    public static function failed(string $error, ?int $status = null): self
    {
        return new self(false, false, [], $error, $status);
    }

    public static function unreachable(string $error): self
    {
        return new self(false, true, [], $error, null);
    }
}
