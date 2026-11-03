<?php

namespace App\Console\Commands;

use App\Ocm\Vulnerabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:vulnerabilities')]
#[Description('Wordfence Intelligence-feed ophalen en de kwetsbaarheden van onze plugins, thema\'s en WordPress-versies bewaren')]
class VulnerabilitiesCommand extends Command
{
    public function handle(): int
    {
        if (!Vulnerabilities::enabled()) {
            $this->warn('OCM_WORDFENCE_API_KEY ontbreekt; overgeslagen.');

            return self::SUCCESS;
        }
        try {
            $result = Vulnerabilities::sync();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$result['records']} kwetsbaarheden in de feed, {$result['matched']} relevant voor onze sites");

        return self::SUCCESS;
    }
}
