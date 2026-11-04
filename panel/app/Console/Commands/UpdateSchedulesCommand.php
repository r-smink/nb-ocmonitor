<?php

namespace App\Console\Commands;

use App\Models\UpdateSchedule;
use App\Ocm\UpdateSchedules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:update-schedules {--now= : Alleen dit schema (id) direct uitvoeren}')]
#[Description('Automatische updates klaarzetten volgens de update-schema\'s')]
class UpdateSchedulesCommand extends Command
{
    public function handle(): int
    {
        if ($id = $this->option('now')) {
            $results = [(int) $id => UpdateSchedules::run(UpdateSchedule::query()->findOrFail((int) $id))];
        } else {
            $results = UpdateSchedules::runDue();
        }
        foreach ($results as $id => $r) {
            $this->info("Schema {$id}: {$r['queued']} update(s) op {$r['sites']} site(s), " . count($r['excluded']) . ' uitgesloten, ' . count($r['skipped']) . ' site(s) overgeslagen');
        }
        if ($results === []) {
            $this->line('Geen schema aan de beurt.');
        }

        return self::SUCCESS;
    }
}
