<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

use NextBuzz\OcMonitor\Client\Admin\Ui;
use NextBuzz\OcMonitor\Client\Smtp\Diagnose;

/** Tab E-maillog: overzicht met filters, detail van één e-mail, export en instellingen. */
final class LogTab
{
    public static function render(): void
    {
        if (!current_user_can(Ui::CAPABILITY)) {
            return;
        }
        $id = isset($_GET['log']) ? (int) $_GET['log'] : 0;
        if ($id > 0) {
            self::renderDetail($id);

            return;
        }
        self::renderList();
    }

    private static function renderList(): void
    {
        $repo = new LogRepository();
        $settings = LogSettings::get();
        $f = LogQuery::normalize(wp_unslash($_GET));
        [$start, $end] = LogQuery::range($f['from'], $f['to'], wp_timezone());
        $stats = $repo->stats(time() - 7 * 86400);
        $result = $repo->search($f, $start, $end, LogQuery::PER_PAGE, LogQuery::offset($f['paged']));
        $pages = LogQuery::pages($result['total']);

        if (!$settings['enabled']) {
            echo '<div class="nbocm-alert is-warn" role="status"><strong>De e-maillog staat uit.</strong> Nieuwe e-mails worden niet gelogd. Zet hem aan bij Instellingen onderaan deze pagina.</div>';
        }

        // Kerncijfers
        echo '<div class="nbocm-kpis">';
        self::kpi('Verzonden', $stats['sent'], 'laatste 7 dagen', 'ok', Ui::url('maillog', ['status' => 'sent']));
        self::kpi('Mislukt', $stats['failed'], 'laatste 7 dagen', $stats['failed'] > 0 ? 'crit' : 'muted', Ui::url('maillog', ['status' => 'failed']));
        self::kpi('Via backup', $stats['backup'], 'laatste 7 dagen', $stats['backup'] > 0 ? 'warn' : 'muted', Ui::url('maillog', ['status' => 'backup']));
        printf(
            '<div class="nbocm-kpi is-info"><span class="nbocm-kpi-label">Bewaartermijn</span><strong class="is-text">%s</strong><small>%s</small></div>',
            esc_html($settings['enabled'] ? (string) LogSettings::RETENTIONS[$settings['retention_days']] : 'Log staat uit'),
            esc_html($settings['log_content'] ? 'Inhoud wordt bewaard' : 'Inhoud wordt niet bewaard')
        );
        echo '</div>';

        self::renderChart($repo);

        Ui::cardOpen('E-mails', 'Alle e-mails die deze site via wp_mail() verstuurt, van WordPress zelf, plugins en het thema.', 'nbocm-log-list');

        // Statusfilter en export
        echo '<div class="nbocm-log-bar"><nav class="nbocm-filter" aria-label="Filter op status">';
        foreach (LogQuery::STATUSES as $status => $label) {
            $args = LogQuery::args(['status' => $status] + $f);
            printf(
                '<a href="%s" class="%s"%s>%s</a>',
                esc_url(Ui::url('maillog', $args)),
                $f['status'] === $status ? 'is-active' : '',
                $f['status'] === $status ? ' aria-current="true"' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
        $csv = wp_nonce_url(add_query_arg(['action' => 'nb_ocm_smtp_log_csv'] + LogQuery::args($f), admin_url('admin-post.php')), 'nb_ocm_smtp_log_csv');
        printf('<a class="nbocm-btn is-secondary is-sm" href="%s">Exporteer CSV</a>', esc_url($csv));
        echo '</div>';

        // Zoeken en datum
        printf('<form method="get" action="%s" class="nbocm-log-search" role="search">', esc_url(admin_url('admin.php')));
        printf('<input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="maillog">', esc_attr(Ui::SLUG));
        if ($f['status'] !== 'all') {
            printf('<input type="hidden" name="status" value="%s">', esc_attr($f['status']));
        }
        printf('<label class="nbocm-log-q"><span class="screen-reader-text">Zoeken</span><input type="search" name="s" value="%s" placeholder="Zoek op ontvanger, onderwerp, fout of bron"></label>', esc_attr($f['s']));
        printf('<label class="nbocm-log-date"><span>Van</span><input type="date" name="from" value="%s"></label>', esc_attr($f['from']));
        printf('<label class="nbocm-log-date"><span>Tot en met</span><input type="date" name="to" value="%s"></label>', esc_attr($f['to']));
        echo '<button type="submit" class="nbocm-btn is-secondary is-sm">Filteren</button>';
        if ($f['s'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['status'] !== 'all') {
            printf('<a class="nbocm-log-reset" href="%s">Wissen</a>', esc_url(Ui::url('maillog')));
        }
        echo '</form>';

        // Tabel met bulkverwijderen
        printf('<form method="post" action="%s" id="nbocm-log-bulk" onsubmit="return confirm(\'Geselecteerde e-mails verwijderen uit de log?\');">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field('nb_ocm_smtp_log_delete');
        echo '<input type="hidden" name="action" value="nb_ocm_smtp_log_delete">';
        echo '<div class="nbocm-table-wrap"><table class="nbocm-table nbocm-log-table"><thead><tr>';
        echo '<th class="nbocm-log-check"><input type="checkbox" id="nbocm-log-all" aria-label="Alles selecteren"></th>';
        echo '<th>Status</th><th>Onderwerp</th><th>Aan</th><th>Bron</th><th>Verbinding</th><th>Datum</th></tr></thead><tbody>';
        if ($result['rows'] === []) {
            $filtered = LogQuery::args($f) !== [];
            printf('<tr><td colspan="7" class="nbocm-empty">%s</td></tr>', esc_html($filtered ? 'Geen e-mails gevonden met deze filters.' : ($settings['enabled'] ? 'Nog geen e-mails gelogd. Zodra de site een e-mail verstuurt, staat hij hier.' : 'Er staan geen e-mails in de log.')));
        }
        foreach ($result['rows'] as $row) {
            self::renderRow($row);
        }
        echo '</tbody></table></div>';

        echo '<div class="nbocm-log-foot">';
        echo '<div class="nbocm-inline">';
        echo '<button type="submit" class="nbocm-btn is-danger is-sm">Geselecteerde verwijderen</button>';
        printf('<span class="nbocm-muted">%s</span>', esc_html($result['total'] === 1 ? '1 e-mail' : sprintf('%s e-mails', number_format_i18n($result['total']))));
        echo '</div>';
        self::pager($f, $pages);
        echo '</div></form>';
        Ui::cardClose();

        echo '<div class="nbocm-grid">';
        self::renderSettings($settings);
        self::renderPurge();
        echo '</div>';
        self::script();
    }

    /** @param array<string, mixed> $row */
    private static function renderRow(array $row): void
    {
        [$label, $tone] = self::status($row);
        $url = Ui::url('maillog', ['log' => (int) $row['id']]);
        $subject = (string) $row['subject'] !== '' ? (string) $row['subject'] : '(geen onderwerp)';
        $badges = '';
        if (!empty($row['is_test'])) {
            $badges .= ' <span class="nbocm-tag">Test</span>';
        }
        if (!empty($row['is_alert'])) {
            $badges .= ' <span class="nbocm-tag">Melding</span>';
        }
        if (!empty($row['resent_of'])) {
            $badges .= ' <span class="nbocm-tag">Opnieuw</span>';
        }
        $error = (string) ($row['error'] ?? '');
        $to = (string) $row['to_email'];
        $time = (int) $row['created_at'];

        echo '<tr>';
        printf('<td class="nbocm-log-check"><input type="checkbox" name="ids[]" value="%d" aria-label="Selecteer e-mail %d"></td>', (int) $row['id'], (int) $row['id']);
        printf('<td><span class="nbocm-pill is-%s">%s</span></td>', esc_attr($tone), esc_html($label));
        printf(
            '<td class="nbocm-log-subject"><a href="%s">%s</a>%s%s</td>',
            esc_url($url),
            esc_html(MailParts::clip($subject, 120)),
            $badges, // vaste HTML
            $error !== '' && $row['status'] !== 'sent' ? '<small class="nbocm-log-err">' . esc_html(MailParts::clip($error, 140)) . '</small>' : ''
        );
        printf('<td class="nbocm-log-to" title="%s">%s</td>', esc_attr($to), esc_html(MailParts::clip($to, 60) . (strlen($to) > 60 ? '…' : '')));
        printf('<td>%s</td>', esc_html((string) $row['initiator'] !== '' ? (string) $row['initiator'] : Initiator::UNKNOWN));
        $connection = Export::connectionLabel((string) $row['connection']);
        printf('<td>%s</td>', $connection !== '' ? esc_html($connection) : '<span class="nbocm-muted">–</span>');
        printf('<td class="nbocm-log-date-cell">%s<small>%s</small></td>', esc_html(wp_date('j M Y', $time)), esc_html(wp_date('H:i:s', $time)));
        echo '</tr>';
    }

    /** @param array{status: string, s: string, from: string, to: string, paged: int} $f */
    private static function pager(array $f, int $pages): void
    {
        if ($pages <= 1) {
            return;
        }
        $page = min($f['paged'], $pages);
        echo '<nav class="nbocm-pager" aria-label="Paginering">';
        if ($page > 1) {
            printf('<a class="nbocm-btn is-secondary is-sm" href="%s">‹ Vorige</a>', esc_url(Ui::url('maillog', LogQuery::args(['paged' => $page - 1] + $f, true))));
        }
        printf('<span class="nbocm-muted">Pagina %d van %d</span>', $page, $pages);
        if ($page < $pages) {
            printf('<a class="nbocm-btn is-secondary is-sm" href="%s">Volgende ›</a>', esc_url(Ui::url('maillog', LogQuery::args(['paged' => $page + 1] + $f, true))));
        }
        echo '</nav>';
    }

    /** @param array{enabled: bool, log_content: bool, retention_days: int} $settings */
    private static function renderSettings(array $settings): void
    {
        Ui::cardOpen('Instellingen', 'Wat de e-maillog bewaart en hoe lang.');
        Ui::formOpen('nb_ocm_smtp_log_save');
        Ui::toggle('log[enabled]', $settings['enabled'], 'E-maillog aan', 'Elke e-mail van de site komt in de log, met ontvanger, onderwerp, bron en resultaat.');
        Ui::toggle('log[log_content]', $settings['log_content'], 'Inhoud bewaren', 'Nodig voor de voorbeeldweergave en om opnieuw te versturen. Let op: e-mails kunnen persoonsgegevens bevatten (AVG). Bijlagen worden nooit bewaard.');
        $options = '';
        foreach (LogSettings::RETENTIONS as $days => $label) {
            $options .= sprintf('<option value="%d"%s>%s</option>', $days, selected($settings['retention_days'], $days, false), esc_html($label));
        }
        Ui::field('Bewaren', sprintf('<select id="nbocm-log-retention" name="log[retention_days]">%s</select>', $options), 'Oudere e-mails worden elke dag automatisch verwijderd.', 'nbocm-log-retention');
        echo '<div class="nbocm-actions">';
        Ui::button('Opslaan');
        echo '</div></form>';
        Ui::cardClose();
    }

    private static function renderPurge(): void
    {
        Ui::cardOpen('Alles verwijderen', 'Maakt de hele e-maillog leeg. Dit kan niet ongedaan worden gemaakt.', 'is-danger');
        Ui::formOpen('nb_ocm_smtp_log_purge');
        Ui::field('Bevestig', sprintf('<input type="text" id="nbocm-log-confirm" name="confirm" value="" autocomplete="off" placeholder="%s">', esc_attr(LogModule::PURGE_CONFIRM)), sprintf('Typ <strong>%s</strong> om te bevestigen.', esc_html(LogModule::PURGE_CONFIRM)), 'nbocm-log-confirm');
        echo '<div class="nbocm-actions">';
        Ui::button('Alles verwijderen', 'danger');
        echo '</div></form>';
        Ui::cardClose();
    }

    private static function renderDetail(int $id): void
    {
        $row = (new LogRepository())->find($id);
        printf('<p class="nbocm-back"><a href="%s">‹ Terug naar de e-maillog</a></p>', esc_url(Ui::url('maillog')));
        if ($row === null) {
            echo '<div class="nbocm-alert is-warn" role="status">Deze e-mail staat niet (meer) in de log.</div>';

            return;
        }
        [$label, $tone] = self::status($row);
        $time = (int) $row['created_at'];
        $hasContent = is_string($row['content'] ?? null);
        $attachments = json_decode((string) ($row['attachments'] ?? ''), true);
        $attachments = is_array($attachments) ? array_filter($attachments, 'is_string') : [];
        $muted = static function (string $text): string {
            return '<span class="nbocm-muted">' . esc_html($text) . '</span>';
        };
        $text = static function ($value) use ($muted): string {
            $value = (string) $value;

            return $value !== '' ? esc_html($value) : $muted('–');
        };

        echo '<div class="nbocm-grid nbocm-log-detail">';

        Ui::cardOpen(sprintf('E-mail #%d', $id), (string) $row['subject'] !== '' ? (string) $row['subject'] : '(geen onderwerp)');
        $source = esc_html((string) $row['initiator'] !== '' ? (string) $row['initiator'] : Initiator::UNKNOWN);
        if ((string) $row['initiator_file'] !== '') {
            $source .= '<br><code>' . esc_html((string) $row['initiator_file']) . '</code>';
        }
        $statusHtml = sprintf('<span class="nbocm-pill is-%s">%s</span>', esc_attr($tone), esc_html($label));
        if (!empty($row['is_test'])) {
            $statusHtml .= ' <span class="nbocm-tag">Testmail</span>';
        }
        if (!empty($row['is_alert'])) {
            $statusHtml .= ' <span class="nbocm-tag">Melding van de module</span>';
        }
        $rows = [
            ['Status', $statusHtml],
            ['Datum', esc_html(wp_date('j F Y H:i:s', $time))],
            ['Aan', $text($row['to_email'])],
        ];
        if ((string) $row['cc'] !== '') {
            $rows[] = ['Cc', esc_html((string) $row['cc'])];
        }
        if ((string) $row['bcc'] !== '') {
            $rows[] = ['Bcc', esc_html((string) $row['bcc'])];
        }
        $rows[] = ['Van', $text($row['from_email'])];
        $rows[] = ['Onderwerp', $text($row['subject'])];
        $rows[] = ['Bron', $source];
        $connection = Export::connectionLabel((string) $row['connection']);
        $rows[] = ['Verbinding', $connection !== '' ? esc_html($connection) : $muted('–')];
        $rows[] = ['Message-ID', (string) $row['message_id'] !== '' ? '<code>' . esc_html((string) $row['message_id']) . '</code>' : $muted('–')];
        $rows[] = ['Bijlagen', $attachments !== [] ? esc_html(implode(', ', $attachments)) : $muted('Geen')];
        if (!empty($row['resent_of'])) {
            $rows[] = ['Opnieuw verstuurd van', sprintf('<a href="%s">E-mail #%d</a>', esc_url(Ui::url('maillog', ['log' => (int) $row['resent_of']])), (int) $row['resent_of'])];
        }
        Ui::definitions($rows);
        Ui::cardClose();

        // Acties
        Ui::cardOpen('Acties');
        if ($hasContent) {
            Ui::formOpen('nb_ocm_smtp_log_resend');
            printf('<input type="hidden" name="id" value="%d">', $id);
            Ui::field('Opnieuw versturen naar', '<input type="text" id="nbocm-log-resend-to" name="to" value="" placeholder="' . esc_attr(MailParts::clip((string) $row['to_email'], 80)) . '" autocomplete="off">', 'Leeg laten om naar de oorspronkelijke ontvangers te sturen. Bij een ander adres gaan Cc en Bcc niet mee.', 'nbocm-log-resend-to');
            echo '<div class="nbocm-actions">';
            Ui::button('Opnieuw versturen');
            echo '</div></form>';
        } else {
            echo '<p class="nbocm-muted" style="margin-top:0">Opnieuw versturen kan alleen als de inhoud is gelogd (Instellingen → Inhoud bewaren).</p>';
        }
        echo '<div class="nbocm-actions nbocm-log-actions">';
        printf('<a class="nbocm-btn is-secondary" href="%s">Exporteer EML</a>', esc_url(wp_nonce_url(add_query_arg(['action' => 'nb_ocm_smtp_log_eml', 'id' => $id], admin_url('admin-post.php')), 'nb_ocm_smtp_log_eml')));
        printf('<form method="post" action="%s" onsubmit="return confirm(\'Deze e-mail verwijderen uit de log?\');">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field('nb_ocm_smtp_log_delete');
        printf('<input type="hidden" name="action" value="nb_ocm_smtp_log_delete"><input type="hidden" name="id" value="%d">', $id);
        Ui::button('Verwijderen', 'danger');
        echo '</form></div>';
        Ui::cardClose();

        // Fout en uitleg
        $error = (string) ($row['error'] ?? '');
        if ($error !== '') {
            $sentViaBackup = $row['status'] === 'sent' && $row['connection'] === 'backup';
            Ui::cardOpen($sentViaBackup ? 'De primaire verbinding faalde' : 'Waarom is deze e-mail niet verstuurd?', $sentViaBackup ? 'De backupverbinding heeft de e-mail alsnog verstuurd.' : '', 'is-wide');
            $d = Diagnose::explain($error);
            echo '<div class="nbocm-diag">';
            printf('<h3>%s</h3><p>%s</p>', esc_html((string) $d['title']), esc_html((string) $d['text']));
            echo '<ol>';
            foreach ((array) $d['steps'] as $step) {
                printf('<li>%s</li>', esc_html((string) $step));
            }
            echo '</ol></div>';
            printf('<p style="margin-top:12px"><strong>Melding:</strong> <code>%s</code></p>', esc_html($error));
            if ((string) $row['backup_error'] !== '') {
                printf('<p><strong>Backupverbinding:</strong> <code>%s</code></p>', esc_html((string) $row['backup_error']));
            }
            Ui::cardClose();
        }

        // Inhoud
        Ui::cardOpen('Inhoud', $hasContent ? 'Voorbeeld in een afgeschermd venster. Scripts en externe afbeeldingen worden niet geladen.' : '', 'is-wide');
        if ($hasContent) {
            printf(
                '<iframe class="nbocm-log-frame" sandbox="" referrerpolicy="no-referrer" title="Inhoud van e-mail %d" srcdoc="%s"></iframe>',
                $id,
                esc_attr(MailParts::previewDocument((string) $row['content'], (string) $row['content_type']))
            );
        } else {
            echo '<p class="nbocm-muted" style="margin:0">De inhoud van deze e-mail is niet gelogd. Zet <strong>Inhoud bewaren</strong> aan in de instellingen van de e-maillog om de inhoud van nieuwe e-mails te bewaren.</p>';
        }
        Ui::cardClose();

        // Headers
        Ui::cardOpen('Headers', 'Zoals de afzender ze aan wp_mail() meegaf.', 'is-wide');
        $headers = (string) ($row['headers'] ?? '');
        printf('<pre class="nbocm-pre">%s</pre>', esc_html($headers !== '' ? $headers : '(geen extra headers)'));
        if ((string) ($row['sent_headers'] ?? '') !== '') {
            printf('<details><summary>Verstuurde headers</summary><pre class="nbocm-pre">%s</pre></details>', esc_html((string) $row['sent_headers']));
        }
        Ui::cardClose();

        echo '</div>';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string} [label, toon]
     */
    public static function status(array $row): array
    {
        $label = Export::statusLabel((string) $row['status'], (string) $row['connection']);
        if ($row['status'] === 'sent') {
            return [$label, $row['connection'] === 'backup' ? 'warn' : 'ok'];
        }

        return [$label, $row['status'] === 'failed' ? 'crit' : 'muted'];
    }

    /** Staafgrafiek van de laatste 14 dagen (Chart, inline SVG). */
    private static function renderChart(LogRepository $repo): void
    {
        $tz = wp_timezone();
        [$since, $until] = Chart::range(time(), $tz);
        $days = $repo->daily($since, $until, $tz);
        $sent = $failed = $backup = 0;
        foreach ($days as $d) {
            $sent += $d['sent'];
            $failed += $d['failed'];
            $backup += $d['backup'];
        }
        Ui::cardOpen('Laatste ' . Chart::DAYS . ' dagen', sprintf('%s verzonden (waarvan %s via backup), %s mislukt.', number_format_i18n($sent), number_format_i18n($backup), number_format_i18n($failed)), 'nbocm-chart');
        echo '<div class="nbocm-chart-wrap">' . Chart::svg($days, 'E-mails per dag, laatste ' . Chart::DAYS . ' dagen') . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Chart escapet zelf
        echo Chart::legend(); // phpcs:ignore WordPress.Security.EscapeOutput
        Ui::cardClose();
    }

    private static function kpi(string $label, int $value, string $hint, string $tone, string $url): void
    {
        printf(
            '<a class="nbocm-kpi is-%s" href="%s"><span class="nbocm-kpi-label">%s</span><strong>%s</strong><small>%s</small></a>',
            esc_attr($tone),
            esc_url($url),
            esc_html($label),
            esc_html(number_format_i18n($value)),
            esc_html($hint)
        );
    }

    /** Alles selecteren in de tabel. */
    private static function script(): void
    {
        echo '<script>(function(){var a=document.getElementById("nbocm-log-all");if(!a)return;'
            . 'a.addEventListener("change",function(){document.querySelectorAll("#nbocm-log-bulk input[name=\'ids[]\']").forEach(function(c){c.checked=a.checked;});});})();</script>';
    }
}
