<?php

namespace App\Console\Commands;

use App\Ocm\Activity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:verify-log')]
#[Description('Controleert de hash-keten van het activity log')]
class VerifyLogCommand extends Command
{
    public function handle(): int
    {
        $result = Activity::verifyChain();
        if ($result['ok']) {
            $this->info("Activity log intact ({$result['checked']} regels).");
            return self::SUCCESS;
        }
        $this->error("Hash-keten verbroken bij regel #{$result['broken_at']} (na {$result['checked']} geldige regels).");

        return self::FAILURE;
    }
}
