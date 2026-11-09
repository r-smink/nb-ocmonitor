<?php

namespace App\Console\Commands;

use App\Ocm\Changelogs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:changelogs')]
#[Description('Changelogs van openstaande plugin-updates ophalen (en met Claude samenvatten als dat aan staat)')]
class ChangelogsCommand extends Command
{
    public function handle(): int
    {
        $r = Changelogs::refresh();
        $this->info("{$r['fetched']} changelog(s) opgehaald, {$r['summarized']} samengevat");

        return self::SUCCESS;
    }
}
