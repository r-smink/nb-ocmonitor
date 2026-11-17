<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

use NextBuzz\OcMonitor\Client\Admin\Ui;

/** Tab Meldingen in de clientpagina: instellingen, testmelding, geschiedenis en uitleg. */
final class AlertsTab
{
    private const CHANNELS = ['email' => 'E-mail', 'webhook' => 'Webhook'];

    public static function render(): void
    {
        if (!current_user_can(Ui::CAPABILITY)) {
            return;
        }
        $s = AlertSettings::get();
        $test = AlertsModule::takeTestResult();

        ProblemNotice::inline();
        echo '<div class="nbocm-grid nbocm-alerts">';

        // Instellingen
        Ui::cardOpen('Meldingen bij mailproblemen', 'Krijg bericht zodra deze site een e-mail niet kan versturen, per e-mail of via een webhook (Slack, Teams, Zapier en dergelijke).', 'is-wide');
        Ui::formOpen('nb_ocm_smtp_alerts_save');

        $emails = '<div class="nbocm-stack">';
        for ($i = 0; $i < AlertSettings::MAX_TARGETS; $i++) {
            $emails .= sprintf(
                '<input type="email" id="nbocm-alert-email-%1$d" name="alerts[emails][]" value="%2$s" placeholder="%3$s" autocomplete="off" aria-label="E-mailadres %4$d">',
                $i,
                esc_attr((string) ($s['emails'][$i] ?? '')),
                $i === 0 ? esc_attr((string) get_option('admin_email')) : '',
                $i + 1
            );
        }
        $emails .= '</div>';
        Ui::field('E-mailadressen', $emails, 'Maximaal 3. Gaat de mail via SMTP mis, dan versturen we de melding via de standaard mailfunctie van de server, zodat hij toch aankomt.', 'nbocm-alert-email-0');

        $hooks = '<div class="nbocm-stack">';
        for ($i = 0; $i < AlertSettings::MAX_TARGETS; $i++) {
            $hooks .= sprintf(
                '<input type="url" id="nbocm-alert-hook-%1$d" name="alerts[webhooks][]" value="%2$s" placeholder="%3$s" autocomplete="off" spellcheck="false" aria-label="Webhook %4$d">',
                $i,
                esc_attr((string) ($s['webhooks'][$i] ?? '')),
                $i === 0 ? 'https://hooks.slack.com/services/…' : '',
                $i + 1
            );
        }
        $hooks .= '</div>';
        Ui::field('Webhooks', $hooks, 'Maximaal 3, alleen <code>https://</code>. We sturen een POST met JSON (velden <code>event</code>, <code>message</code>, <code>mail</code>, <code>suppressed</code>). Interne adressen worden geweigerd.', 'nbocm-alert-hook-0');

        echo '<div class="nbocm-field"><span class="nbocm-label">Melden bij</span><div>';
        Ui::toggle('alerts[events][failed]', $s['events']['failed'], AlertSettings::TYPES['failed'], 'De e-mail is definitief niet verstuurd, ook niet via een backupverbinding.');
        Ui::toggle('alerts[events][backup_used]', $s['events']['backup_used'], AlertSettings::TYPES['backup_used'], 'De SMTP-verbinding faalde, maar de backupverbinding heeft de e-mail verstuurd.');
        Ui::toggle('alerts[events][recovered]', $s['events']['recovered'], AlertSettings::TYPES['recovered'], 'De eerste e-mail die weer gewoon verstuurd is, na een eerdere melding.');
        echo '</div></div>';

        $select = '<select id="nbocm-alert-throttle" name="alerts[throttle]">';
        foreach (AlertSettings::THROTTLES as $minutes) {
            $select .= sprintf('<option value="%d"%s>%s</option>', $minutes, selected($s['throttle'], $minutes, false), esc_html(self::minutes($minutes)));
        }
        $select .= '</select>';
        Ui::field('Bundelen', $select, 'Hooguit één melding per soort fout in deze periode. Wat daarna gebeurt, tellen we op en sturen we aan het eind als één samenvatting.', 'nbocm-alert-throttle');

        echo '<div class="nbocm-actions">';
        Ui::button('Instellingen opslaan');
        echo '</div></form>';
        Ui::cardClose();

        // Testmelding
        $channels = count($s['emails']) + count($s['webhooks']);
        Ui::cardOpen('Testmelding', 'Verstuur een nagebootste melding over een mislukte e-mail naar alle kanalen hierboven.');
        Ui::definitions([
            ['E-mail', $s['emails'] !== [] ? esc_html(implode(', ', $s['emails'])) : '<span class="nbocm-muted">Geen adres ingesteld</span>'],
            ['Webhooks', $s['webhooks'] !== [] ? esc_html(implode(', ', array_map([AlertSettings::class, 'webhookLabel'], $s['webhooks']))) : '<span class="nbocm-muted">Geen webhook ingesteld</span>'],
        ]);
        Ui::formOpen('nb_ocm_smtp_alerts_test');
        echo '<div class="nbocm-actions">';
        if ($channels > 0) {
            Ui::button('Testmelding versturen', 'secondary');
            echo '<small class="nbocm-muted">Direct, zonder bundelen.</small>';
        } else {
            echo '<button type="button" class="nbocm-btn is-secondary" disabled>Testmelding versturen</button><small class="nbocm-muted">Vul eerst een e-mailadres of webhook in.</small>';
        }
        echo '</div></form>';
        if ($test !== null) {
            echo '<ul class="nbocm-steps nbocm-alert-results">';
            foreach ($test as $r) {
                printf(
                    '<li><span class="ic is-%s" aria-hidden="true">%s</span><div><strong>%s · %s</strong><small>%s</small></div></li>',
                    $r['ok'] ? 'ok' : 'crit',
                    $r['ok'] ? '✓' : '✕',
                    esc_html(self::CHANNELS[$r['channel']] ?? (string) $r['channel']),
                    esc_html((string) $r['target']),
                    esc_html($r['ok'] ? ($r['channel'] === 'email' ? 'Verstuurd. Controleer of hij aankomt, ook in de spammap.' : 'Ontvangen door de webhook.') : (string) $r['error'])
                );
            }
            echo '</ul>';
        }
        Ui::cardClose();

        // Uitleg
        Ui::cardOpen('Wanneer krijg je een melding?');
        echo '<ul class="nbocm-alert-explain">';
        printf('<li>%s<span>%s</span></li>', self::pillHtml('Niet verstuurd', 'crit'), esc_html('Een e-mail is definitief mislukt. Je krijgt de oorzaak in gewone taal en de stappen om het op te lossen.'));
        printf('<li>%s<span>%s</span></li>', self::pillHtml('Backup gebruikt', 'warn'), esc_html('De SMTP-verbinding werkt niet, maar de mail is via de backup toch verstuurd. Tijd om de verbinding na te kijken.'));
        printf('<li>%s<span>%s</span></li>', self::pillHtml('Werkt weer', 'ok'), esc_html('De eerste e-mail die weer lukt, met hoeveel er tussendoor misgingen. Alleen als je eerder een melding kreeg.'));
        echo '</ul>';
        printf('<p class="nbocm-note">%s</p>', esc_html(sprintf('Geen stortvloed: per soort fout hooguit één melding per %s; de rest komt als "+12 vergelijkbare fouten" in de volgende melding of in één samenvatting. Testmails tellen niet mee. Next Buzz ziet dezelfde meldingen in het OC Monitor-panel.', $s['throttle'] === 60 ? 'uur' : self::minutes($s['throttle']))));
        Ui::cardClose();

        // Geschiedenis
        $history = History::all();
        Ui::cardOpen('Verstuurde meldingen', sprintf('De laatste %d afleverpogingen, per kanaal.', History::MAX), 'is-wide');
        echo '<div class="nbocm-table-wrap"><table class="nbocm-table"><thead><tr><th>Tijdstip</th><th>Melding</th><th>Kanaal</th><th>Resultaat</th></tr></thead><tbody>';
        if ($history === []) {
            echo '<tr><td colspan="4" class="nbocm-empty">Nog geen meldingen verstuurd.</td></tr>';
        }
        foreach ($history as $h) {
            printf(
                '<tr><td class="nbocm-nowrap">%s</td><td>%s</td><td><strong>%s</strong> <span class="nbocm-muted">%s</span></td><td>%s%s</td></tr>',
                esc_html(wp_date('j M Y H:i', (int) $h['time'])),
                esc_html(self::typeLabel((string) $h['type'])),
                esc_html(self::CHANNELS[$h['channel']] ?? (string) $h['channel']),
                esc_html((string) $h['target']),
                self::pillHtml($h['ok'] ? 'Afgeleverd' : 'Mislukt', $h['ok'] ? 'ok' : 'crit'),
                $h['ok'] || $h['error'] === '' ? '' : '<small class="nbocm-alert-err">' . esc_html((string) $h['error']) . '</small>'
            );
        }
        echo '</tbody></table></div>';
        Ui::cardClose();

        echo '</div>';
    }

    private static function pillHtml(string $label, string $tone): string
    {
        return sprintf('<span class="nbocm-pill is-%s">%s</span>', esc_attr($tone), esc_html($label));
    }

    private static function typeLabel(string $type): string
    {
        return $type === 'test' ? 'Testmelding' : (AlertSettings::TYPES[$type] ?? $type);
    }

    private static function minutes(int $minutes): string
    {
        return $minutes < 60 ? sprintf('%d minuten', $minutes) : ($minutes === 60 ? '1 uur' : sprintf('%d uur', intdiv($minutes, 60)));
    }
}
