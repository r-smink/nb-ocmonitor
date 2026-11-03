<?php

namespace App\Console\Commands;

use App\Ocm\Visual;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:visual')]
#[Description('Screenshots voor en na updates maken en vergelijken')]
class VisualCheckCommand extends Command
{
    public function handle(): int
    {
        Visual::tick();

        return self::SUCCESS;
    }
}
