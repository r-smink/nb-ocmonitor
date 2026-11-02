<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use NextBuzz\OcMonitor\Protocol\KeyPair;

#[Signature('ocm:keygen {--dotenv : Alleen de twee env-regels tonen (voor scripts)}')]
#[Description('Genereert een nieuw Ed25519-sleutelpaar voor het panel')]
class KeygenCommand extends Command
{
    public function handle(): int
    {
        $keys = KeyPair::generate();

        if ($this->option('dotenv')) {
            $this->line('OCM_PANEL_PUBLIC_KEY=' . $keys->publicKeyB64());
            $this->line('OCM_SIGNING_KEY=' . $keys->secretKeyB64());
            return self::SUCCESS;
        }

        $this->info('Nieuw panel-sleutelpaar (' . $keys->keyId() . ')');
        $this->newLine();
        $this->line('In .env (web, scheduler én signer):');
        $this->line('  OCM_PANEL_PUBLIC_KEY=' . $keys->publicKeyB64());
        $this->newLine();
        $this->line('ALLEEN in signer.env (nooit in .env, nooit in git):');
        $this->line('  OCM_SIGNING_KEY=' . $keys->secretKeyB64());
        $this->newLine();
        $this->warn('Let op: een nieuw sleutelpaar betekent dat al gekoppelde sites opnieuw gekoppeld moeten worden (zie docs/protocol.md §7).');

        return self::SUCCESS;
    }
}
