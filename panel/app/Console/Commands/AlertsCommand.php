<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Ocm\Alerts\Alerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ocm:alerts')]
#[Description('Incidenten bijwerken en meldingen versturen (e-mail, Slack, Teams, webhook)')]
class AlertsCommand extends Command
{
    public function handle(): int
    {
        $tracked = Alerts::track();
        $sent = Alerts::enabled() ? Alerts::deliver() : [];
        Alert::query()->whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays((int) config('ocm.alerts.keep_days', 180)))->delete();
        $this->info("{$tracked['opened']} nieuw, {$tracked['resolved']} opgelost, " . array_sum($sent) . ' melding(en) naar ' . count(array_filter($sent)) . ' ontvanger(s)'
            . (Alerts::enabled() ? '' : ' (versturen staat uit)'));

        return self::SUCCESS;
    }
}
