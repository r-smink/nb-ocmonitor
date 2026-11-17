<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

use NextBuzz\OcMonitor\Client\Smtp\Conflicts;
use NextBuzz\OcMonitor\Client\Smtp\Module;
use NextBuzz\OcMonitor\Client\Smtp\Options;

/** Tab SMTP: status, verbinding, afzender, testmail en verbindingscheck. */
final class SmtpTab
{
    public static function render(): void
    {
        $o = Options::get();
        $locked = Options::locked();
        $state = Module::state();
        $result = Module::takeResult();
        $conflicts = Conflicts::active();
        $hasPass = $o['pass'] !== '' || isset($locked['pass']);

        if ($conflicts !== []) {
            printf(
                '<div class="nbocm-alert is-warn" role="status"><strong>Andere mailplugin actief:</strong> %s. Zolang die actief is, verstuurt die plugin de mail en doet deze module niets. Zet die plugin uit (en neem de instellingen over) om over te stappen.</div>',
                esc_html(implode(', ', $conflicts))
            );
        }

        echo '<div class="nbocm-grid">';

        // Status
        Ui::cardOpen('Status', 'Verstuurt deze site mail via SMTP?');
        $last = Module::lastTest();
        Ui::definitions([
            ['Verzending', $state['active'] ? '<span class="nbocm-pill is-ok">Via SMTP</span>' : '<span class="nbocm-pill is-warn">Niet via SMTP</span>'],
            ['Server', $o['host'] !== '' ? esc_html($o['host'] . ':' . $o['port']) . ' <span class="nbocm-muted">· ' . esc_html(['none' => 'onversleuteld', 'ssl' => 'SSL/TLS', 'tls' => 'STARTTLS'][$o['encryption']]) . '</span>' : '<span class="nbocm-muted">Nog niet ingevuld</span>'],
            ['Afzender', $o['from_email'] !== '' ? esc_html(trim($o['from_name'] . ' <' . $o['from_email'] . '>')) : '<span class="nbocm-muted">Zoals WordPress of de plugin het aangeeft</span>'],
            ['Laatste testmail', $last === null ? '<span class="nbocm-muted">Nog niet getest</span>'
                : ($last['ok'] ? '<span class="nbocm-pill is-ok">Gelukt</span>' : '<span class="nbocm-pill is-crit">Mislukt</span>') . ' <span class="nbocm-muted">' . esc_html(wp_date('j M Y H:i', (int) $last['time'])) . '</span>'],
        ]);
        if ($state['reason'] !== null) {
            printf('<p class="nbocm-note">%s</p>', esc_html($state['reason']));
        }
        Ui::cardClose();

        // Testen
        Ui::cardOpen('Testen', 'Controleer de verbinding of verstuur een echte testmail.');
        Ui::formOpen('nb_ocm_smtp_test');
        $user = wp_get_current_user();
        Ui::field('Testmail naar', sprintf('<input type="email" id="nbocm-test-to" name="to" value="%s" required>', esc_attr((string) $user->user_email)), '', 'nbocm-test-to');
        echo '<div class="nbocm-actions">';
        Ui::button('Testmail versturen');
        echo '</div></form>';
        Ui::formOpen('nb_ocm_smtp_check');
        echo '<div class="nbocm-actions" style="margin-top:10px">';
        Ui::button('Verbinding controleren', 'secondary');
        echo '<small class="nbocm-muted">Verbindt en logt in, zonder een mail te versturen.</small></div></form>';
        Ui::cardClose();

        if ($result !== null) {
            self::renderResult($result);
        }

        // Instellingen
        Ui::cardOpen('SMTP-server', 'De gegevens van de mailbox of mailprovider waarmee deze site verstuurt.', 'is-wide');
        Ui::formOpen('nb_ocm_smtp_save');
        $lock = static function (string $field) use ($locked): string {
            return isset($locked[$field]) ? ' disabled' : '';
        };
        $lockNote = static function (string $field) use ($locked): string {
            return isset($locked[$field]) ? 'Vastgezet in wp-config.php met <code>' . esc_html($locked[$field]) . '</code>.' : '';
        };

        Ui::field('Server', sprintf('<input type="text" id="nbocm-host" name="smtp[host]" value="%s" placeholder="smtp.voorbeeld.nl" autocomplete="off" spellcheck="false"%s>', esc_attr($o['host']), $lock('host')), $lockNote('host') ?: 'Bijvoorbeeld smtp.office365.com, smtp.gmail.com of mail.jouwdomein.nl.', 'nbocm-host');

        $segments = '<div class="nbocm-segment">';
        foreach (Options::ENCRYPTIONS as $value => $label) {
            $segments .= sprintf('<label><input type="radio" name="smtp[encryption]" value="%s"%s%s data-port="%d"> %s</label>', esc_attr($value), checked($o['encryption'], $value, false), $lock('encryption'), $value === 'ssl' ? 465 : ($value === 'tls' ? 587 : 25), esc_html($label));
        }
        $segments .= '</div>';
        Ui::field('Versleuteling', $segments, $lockNote('encryption') ?: 'Gebruik altijd versleuteling als de provider dat ondersteunt.');

        Ui::field('Poort', sprintf('<input type="number" class="is-small" id="nbocm-port" name="smtp[port]" value="%d" min="1" max="65535"%s>', (int) $o['port'], $lock('port')), $lockNote('port'), 'nbocm-port');

        echo '<div class="nbocm-field"><span class="nbocm-label">Opties</span><div>';
        Ui::toggle('smtp[autotls]', (bool) $o['autotls'], 'Automatisch STARTTLS', 'Bij "Geen" versleuteling toch versleutelen als de server dat aanbiedt. Zet alleen uit bij een server met een kapot certificaat.');
        Ui::toggle('smtp[auth]', (bool) $o['auth'], 'Inloggen bij de server (authenticatie)', 'Bijna altijd nodig. Alleen uit voor een relay die mail van dit IP-adres zonder login accepteert.', isset($locked['auth']));
        echo '</div></div>';

        Ui::field('Gebruikersnaam', sprintf('<input type="text" id="nbocm-user" name="smtp[user]" value="%s" autocomplete="off" spellcheck="false"%s>', esc_attr($o['user']), $lock('user')), $lockNote('user') ?: 'Meestal het volledige e-mailadres.', 'nbocm-user');

        $passHelp = isset($locked['pass'])
            ? $lockNote('pass')
            : ($hasPass ? 'Er is een wachtwoord opgeslagen (versleuteld). Laat leeg om het te houden.' : 'Wordt versleuteld opgeslagen. Bij Google of Microsoft 365 vaak een app-wachtwoord.');
        $passHtml = sprintf('<input type="password" id="nbocm-pass" name="smtp[pass]" value="" autocomplete="new-password" placeholder="%s"%s>', $hasPass ? '••••••••••' : '', $lock('pass'));
        Ui::field('Wachtwoord', $passHtml, $passHelp, 'nbocm-pass');
        if ($hasPass && !isset($locked['pass'])) {
            echo '<div class="nbocm-field"><span class="nbocm-label"></span><div>';
            Ui::toggle('smtp[clear_pass]', false, 'Opgeslagen wachtwoord verwijderen');
            echo '</div></div>';
        }

        echo '<h3 style="margin:22px 0 4px;font-size:15px">Afzender</h3>';
        Ui::field('Afzender e-mailadres', sprintf('<input type="email" id="nbocm-from" name="smtp[from_email]" value="%s" placeholder="%s"%s>', esc_attr($o['from_email']), esc_attr((string) get_option('admin_email')), $lock('from_email')), $lockNote('from_email') ?: 'Moet bij de SMTP-account horen, anders weigert de server de mail of belandt hij in de spam.', 'nbocm-from');
        Ui::field('Afzendernaam', sprintf('<input type="text" id="nbocm-from-name" name="smtp[from_name]" value="%s" placeholder="%s"%s>', esc_attr($o['from_name']), esc_attr(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)), $lock('from_name')), $lockNote('from_name'), 'nbocm-from-name');
        echo '<div class="nbocm-field"><span class="nbocm-label">Afzender afdwingen</span><div>';
        Ui::toggle('smtp[force_from_email]', (bool) $o['force_from_email'], 'Afzenderadres altijd vervangen', 'Ook als een plugin (bijv. een formulier) een ander adres opgeeft. Dat adres komt dan bij "Antwoorden aan", zodat beantwoorden blijft werken. Aanbevolen.');
        Ui::toggle('smtp[force_from_name]', (bool) $o['force_from_name'], 'Afzendernaam altijd vervangen', 'Uit: plugins mogen hun eigen naam gebruiken; alleen "WordPress" wordt vervangen.');
        Ui::toggle('smtp[return_path]', (bool) $o['return_path'], 'Return-Path gelijk aan de afzender', 'Bounces (onbestelbare mail) komen dan terug bij het afzenderadres.');
        echo '</div></div>';

        echo '<div class="nbocm-actions">';
        Ui::button('Instellingen opslaan');
        echo '</div></form>';
        echo '<p class="nbocm-note">Alles kan ook in wp-config.php: <code>NB_OCM_SMTP_HOST</code>, <code>_PORT</code>, <code>_ENCRYPTION</code> (none/ssl/tls), <code>_AUTH</code>, <code>_USER</code>, <code>_PASS</code>, <code>_FROM</code>, <code>_FROM_NAME</code>. Die gaan voor op wat hier staat.</p>';
        Ui::cardClose();

        \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupSection::render();

        echo '</div>';
        self::portScript();
    }

    /** @param array<string, mixed> $r */
    private static function renderResult(array $r): void
    {
        if ($r['type'] === 'check') {
            Ui::cardOpen('Verbindingscheck', $r['ok'] ? 'Alle stappen zijn gelukt.' : 'Hieronder zie je bij welke stap het misgaat.', 'is-wide');
            echo '<ul class="nbocm-steps">';
            foreach ((array) $r['steps'] as $step) {
                $tone = $step['ok'] === true ? 'ok' : ($step['ok'] === false ? 'crit' : 'muted');
                $icon = $step['ok'] === true ? '✓' : ($step['ok'] === false ? '✕' : 'i');
                printf('<li><span class="ic is-%s" aria-hidden="true">%s</span><div><strong>%s</strong><small>%s</small></div></li>', $tone, $icon, esc_html((string) $step['label']), esc_html((string) $step['detail']));
            }
            echo '</ul>';
            Ui::cardClose();

            return;
        }

        Ui::cardOpen('Resultaat testmail', $r['ok'] ? sprintf('Verstuurd naar %s.', $r['to']) : sprintf('Niet verstuurd naar %s.', $r['to']), 'is-wide');
        if (!$r['ok'] && is_array($r['diagnose'])) {
            $d = $r['diagnose'];
            echo '<div class="nbocm-diag">';
            printf('<h3>%s</h3><p>%s</p>', esc_html((string) $d['title']), esc_html((string) $d['text']));
            echo '<ol>';
            foreach ((array) $d['steps'] as $step) {
                printf('<li>%s</li>', esc_html((string) $step));
            }
            echo '</ol></div>';
            printf('<p style="margin-top:12px"><strong>Melding:</strong> <code>%s</code></p>', esc_html((string) $r['error']));
        } elseif ($r['ok']) {
            echo '<p>De server heeft de mail geaccepteerd. Komt hij niet aan, kijk dan in de spammap en controleer SPF en DKIM van het afzenderdomein.</p>';
        }
        if ((string) $r['debug'] !== '') {
            printf('<details%s><summary>SMTP-dialoog</summary><pre class="nbocm-pre">%s</pre></details>', $r['ok'] ? '' : ' open', esc_html((string) $r['debug']));
        }
        Ui::cardClose();
    }

    /** Bij het kiezen van een versleuteling de bijbehorende standaardpoort invullen. */
    private static function portScript(): void
    {
        echo '<script>(function(){var p=document.getElementById("nbocm-port");if(!p)return;'
            . 'document.querySelectorAll("input[name=\'smtp[encryption]\']").forEach(function(r){r.addEventListener("change",function(){'
            . 'if(["25","465","587",""].indexOf(p.value)!==-1&&!p.disabled){p.value=r.getAttribute("data-port");}});});})();</script>';
    }
}
