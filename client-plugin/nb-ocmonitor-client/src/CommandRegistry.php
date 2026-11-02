<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * De allowlist: alleen hier geregistreerde commands bestaan. Elk command hoort bij één scope.
 * Er is bewust geen command voor willekeurige code, SQL of bestanden.
 */
final class CommandRegistry
{
    /** @var array<string, array{scope: string, handler: callable}> */
    private array $commands = [];

    /**
     * @param callable(array<string, mixed>, CommandContext): array<string, mixed> $handler
     */
    public function add(string $command, string $scope, callable $handler): void
    {
        $this->commands[$command] = ['scope' => $scope, 'handler' => $handler];
    }

    public function has(string $command): bool
    {
        return isset($this->commands[$command]);
    }

    public function scopeOf(string $command): string
    {
        return $this->commands[$command]['scope'];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function run(string $command, array $args, CommandContext $context): array
    {
        return ($this->commands[$command]['handler'])($args, $context);
    }
}
