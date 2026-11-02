<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

final class CommandContext
{
    public string $jobId;
    public int $now;
    public SiteConfig $config;

    public function __construct(string $jobId, int $now, SiteConfig $config)
    {
        $this->jobId = $jobId;
        $this->now = $now;
        $this->config = $config;
    }
}
