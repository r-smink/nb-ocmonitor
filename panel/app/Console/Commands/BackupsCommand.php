<?php

namespace App\Console\Commands;

use App\Ocm\Backups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:backups')]
#[Description('Mislukte backups afhandelen (updates weigeren) en verlopen backups uit Spaces verwijderen')]
class BackupsCommand extends Command
{
    public function handle(): int
    {
        Backups::tick();

        return self::SUCCESS;
    }
}
