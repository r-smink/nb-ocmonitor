<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Backup;

use NextBuzz\OcMonitor\Client\Admin\Ui;
use NextBuzz\OcMonitor\Client\Smtp\Options;

/** Kaart "Backupverbinding" onderaan de tab SMTP. */
final class BackupSection
{
    public static function render(): void
    {
        $b = BackupOptions::get();
        $primary = Options::get();
        $result = BackupModule::takeResult();
        $hasPass = $b['pass'] !== '';

        Ui::cardOpen('Backupverbinding', 'Een tweede SMTP-server die alleen wordt gebruikt als de verbinding hierboven faalt.', 'is-wide');

        if ($b['enabled']) {
            if (BackupOptions::isUsable($b)) {
                Ui::pill('Staat klaar', 'ok');
            } elseif (!BackupOptions::isComplete($b)) {
                Ui::pill('Nog niet compleet', 'warn');
            } else {
                Ui::pill('Wachtwoord niet te ontsleutelen', 'crit');
            }
        } else {
            Ui::pill('Uit', 'muted');
        }
        echo '<p class="nbocm-note">Lukt het versturen via de SMTP-server hierboven niet (server onbereikbaar, login geweigerd), dan probeert de site dezelfde mail direct nog een keer via deze backup. '
            . 'In het e-maillog en het panel zie je dan dat de backup is gebruikt. Testmails gaan nooit via de backup.</p>';
        echo '<p class="nbocm-note"><strong>Tip:</strong> kies een andere provider dan de primaire verbinding (bijvoorbeeld een transactionele maildienst naast de eigen mailbox). Valt de ene provider uit, dan werkt de andere meestal nog.</p>';
        if ($b['host'] !== '' && $b['host'] === $primary['host']) {
            echo '<div class="nbocm-alert is-warn" role="status">De backup gebruikt dezelfde server als de primaire verbinding. Valt die server uit, dan helpt de backup niet.</div>';
        }

        if ($result !== null) {
            echo '<ul class="nbocm-steps" style="margin:12px 0">';
            foreach ((array) ($result['steps'] ?? []) as $step) {
                $tone = $step['ok'] === true ? 'ok' : ($step['ok'] === false ? 'crit' : 'muted');
                $icon = $step['ok'] === true ? '✓' : ($step['ok'] === false ? '✕' : 'i');
                printf('<li><span class="ic is-%s" aria-hidden="true">%s</span><div><strong>%s</strong><small>%s</small></div></li>', $tone, $icon, esc_html((string) $step['label']), esc_html((string) $step['detail']));
            }
            echo '</ul>';
        }

        Ui::formOpen('nb_ocm_smtp_backup_save');
        Ui::toggle('backup[enabled]', (bool) $b['enabled'], 'Backupverbinding gebruiken', 'Alleen als de primaire verbinding faalt. De afzender en het Return-Path komen van de instellingen hierboven.');

        Ui::field('Server', sprintf('<input type="text" id="nbocm-bk-host" name="backup[host]" value="%s" placeholder="smtp.andere-provider.nl" autocomplete="off" spellcheck="false">', esc_attr($b['host'])), 'De SMTP-server van de backupprovider.', 'nbocm-bk-host');

        $segments = '<div class="nbocm-segment">';
        foreach (Options::ENCRYPTIONS as $value => $label) {
            $segments .= sprintf('<label><input type="radio" name="backup[encryption]" value="%s"%s data-port="%d"> %s</label>', esc_attr($value), checked($b['encryption'], $value, false), $value === 'ssl' ? 465 : ($value === 'tls' ? 587 : 25), esc_html($label));
        }
        $segments .= '</div>';
        Ui::field('Versleuteling', $segments);

        Ui::field('Poort', sprintf('<input type="number" class="is-small" id="nbocm-bk-port" name="backup[port]" value="%d" min="1" max="65535">', (int) $b['port']), '', 'nbocm-bk-port');

        echo '<div class="nbocm-field"><span class="nbocm-label">Opties</span><div>';
        Ui::toggle('backup[autotls]', (bool) $b['autotls'], 'Automatisch STARTTLS', 'Bij "Geen" versleuteling toch versleutelen als de server dat aanbiedt.');
        Ui::toggle('backup[auth]', (bool) $b['auth'], 'Inloggen bij de server (authenticatie)', 'Bijna altijd nodig.');
        echo '</div></div>';

        Ui::field('Gebruikersnaam', sprintf('<input type="text" id="nbocm-bk-user" name="backup[user]" value="%s" autocomplete="off" spellcheck="false">', esc_attr($b['user'])), '', 'nbocm-bk-user');
        Ui::field(
            'Wachtwoord',
            sprintf('<input type="password" id="nbocm-bk-pass" name="backup[pass]" value="" autocomplete="new-password" placeholder="%s">', $hasPass ? '••••••••••' : ''),
            $hasPass ? 'Er is een wachtwoord opgeslagen (versleuteld). Laat leeg om het te houden.' : 'Wordt versleuteld opgeslagen.',
            'nbocm-bk-pass'
        );
        if ($hasPass) {
            echo '<div class="nbocm-field"><span class="nbocm-label"></span><div>';
            Ui::toggle('backup[clear_pass]', false, 'Opgeslagen wachtwoord verwijderen');
            echo '</div></div>';
        }
        echo '<div class="nbocm-actions">';
        Ui::button('Backup opslaan');
        echo '</div></form>';

        Ui::formOpen('nb_ocm_smtp_backup_check');
        echo '<div class="nbocm-actions" style="margin-top:10px">';
        Ui::button('Backup controleren', 'secondary');
        echo '<small class="nbocm-muted">Verbindt en logt in op de backupserver, zonder een mail te versturen. Sla eerst op.</small></div></form>';
        Ui::cardClose();

        self::portScript();
    }

    /** Bij het kiezen van een versleuteling de bijbehorende standaardpoort invullen. */
    private static function portScript(): void
    {
        echo '<script>(function(){var p=document.getElementById("nbocm-bk-port");if(!p)return;'
            . 'document.querySelectorAll("input[name=\'backup[encryption]\']").forEach(function(r){r.addEventListener("change",function(){'
            . 'if(["25","465","587",""].indexOf(p.value)!==-1){p.value=r.getAttribute("data-port");}});});})();</script>';
    }
}
