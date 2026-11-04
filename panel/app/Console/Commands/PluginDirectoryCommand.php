<?php

namespace App\Console\Commands;

use App\Ocm\PluginDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:plugin-directory')]
#[Description('Gesloten en verlaten plugins opzoeken op wordpress.org')]
class PluginDirectoryCommand extends Command
{
    public function handle(): int
    {
        $r = PluginDirectory::refresh();
        $this->info("{$r['checked']} plugin(s) opgezocht, {$r['closed']} gesloten");

        return self::SUCCESS;
    }
}
