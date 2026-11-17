<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

use NextBuzz\OcMonitor\Client\Admin\Ui;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * E-maillog: de interceptor van wp_mail, de dagelijkse opruiming en de formulieren van de tab E-maillog.
 * Wordt aangeroepen vanuit Smtp\Module::register() als de module aan staat.
 */
final class LogModule
{
    public const CLEANUP_HOOK = 'nb_ocm_smtp_log_cleanup';
    public const PURGE_CONFIRM = 'VERWIJDEREN';

    /** Nog niet gebruikt: de log stuurt geen eigen events naar het panel (dat doen de meldingen). */
    private EventRecorder $recorder;
    private LogRepository $repo;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
        $this->repo = new LogRepository();
    }

    /** @param bool $active neemt de SMTP-module de verzending nu over (Module::state()['active']) */
    public function register(bool $active): void
    {
        (new Interceptor($active, LogSettings::get(), $this->repo))->register();

        add_action(self::CLEANUP_HOOK, [$this, 'cleanup']);
        add_action('init', [self::class, 'schedule']);
        foreach (['activated_plugin', 'deactivated_plugin', 'upgrader_process_complete'] as $hook) {
            add_action($hook, [Initiator::class, 'forgetCache'], 10, 0);
        }

        if (is_admin()) {
            add_action('admin_post_nb_ocm_smtp_log_save', [$this, 'handleSave']);
            add_action('admin_post_nb_ocm_smtp_log_delete', [$this, 'handleDelete']);
            add_action('admin_post_nb_ocm_smtp_log_purge', [$this, 'handlePurge']);
            add_action('admin_post_nb_ocm_smtp_log_resend', [$this, 'handleResend']);
            add_action('admin_post_nb_ocm_smtp_log_csv', [$this, 'handleCsv']);
            add_action('admin_post_nb_ocm_smtp_log_eml', [$this, 'handleEml']);
        }
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + 900, 'daily', self::CLEANUP_HOOK);
        }
    }

    /** Dagelijks: blijven hangende rijen afsluiten en alles ouder dan de bewaartermijn verwijderen. */
    public function cleanup(): void
    {
        $now = time();
        $this->repo->markStale($now - 3600, $now);
        $cutoff = LogSettings::cutoff($now, LogSettings::get()['retention_days']);
        if ($cutoff !== null) {
            $this->repo->deleteOlderThan($cutoff);
        }
    }

    public function handleSave(): void
    {
        Ui::guard('nb_ocm_smtp_log_save');
        $post = wp_unslash($_POST);
        $input = is_array($post['log'] ?? null) ? $post['log'] : [];
        LogSettings::save(LogSettings::fromForm($input));
        Ui::redirect('maillog', 'success', 'Instellingen van de e-maillog opgeslagen.');
    }

    public function handleDelete(): void
    {
        Ui::guard('nb_ocm_smtp_log_delete');
        $post = wp_unslash($_POST);
        $ids = is_array($post['ids'] ?? null) ? $post['ids'] : [$post['id'] ?? 0];
        $ids = array_map('intval', array_filter($ids, 'is_scalar'));
        $n = $this->repo->delete($ids);
        if ($n === 0) {
            Ui::redirect('maillog', 'error', 'Er is niets verwijderd. Selecteer eerst een of meer e-mails.');
        }
        Ui::redirect('maillog', 'success', $n === 1 ? '1 e-mail verwijderd uit de log.' : sprintf('%d e-mails verwijderd uit de log.', $n));
    }

    public function handlePurge(): void
    {
        Ui::guard('nb_ocm_smtp_log_purge');
        $confirm = strtoupper(trim((string) wp_unslash($_POST['confirm'] ?? '')));
        if ($confirm !== self::PURGE_CONFIRM) {
            Ui::redirect('maillog', 'error', sprintf('Typ %s om de hele e-maillog te verwijderen.', self::PURGE_CONFIRM));
        }
        $n = $this->repo->deleteAll();
        Ui::redirect('maillog', 'success', sprintf('De e-maillog is leeggemaakt (%d e-mails verwijderd).', $n));
    }

    public function handleResend(): void
    {
        Ui::guard('nb_ocm_smtp_log_resend');
        $post = wp_unslash($_POST);
        $id = (int) ($post['id'] ?? 0);
        $row = $id > 0 ? $this->repo->find($id) : null;
        if ($row === null) {
            Ui::redirect('maillog', 'error', 'Deze e-mail staat niet (meer) in de log.');
        }
        if (!is_string($row['content'] ?? null)) {
            Ui::redirect('maillog', 'error', 'Opnieuw versturen kan niet: de inhoud van deze e-mail is niet gelogd.');
        }
        $custom = trim((string) ($post['to'] ?? ''));
        if ($custom !== '') {
            $to = MailParts::emails($custom);
            if ($to === []) {
                Ui::redirect('maillog', 'error', 'Vul een geldig e-mailadres in.');
            }
            foreach ($to as $email) {
                if (!is_email($email)) {
                    Ui::redirect('maillog', 'error', sprintf('"%s" is geen geldig e-mailadres.', $email));
                }
            }
        } else {
            $to = MailParts::addresses((string) $row['to_email']);
        }
        // Naar een ander adres: Cc en Bcc niet opnieuw meesturen. Bijlagen zijn niet bewaard.
        $headers = MailParts::resendHeaders(MailParts::headerLines((string) ($row['headers'] ?? '')), $custom !== '');
        $subject = (string) $row['subject'];
        $content = (string) $row['content'];
        $ok = Interceptor::resend($id, static function () use ($to, $subject, $content, $headers): bool {
            return (bool) wp_mail($to, $subject, $content, $headers);
        });
        Ui::redirect('maillog', $ok ? 'success' : 'error', $ok
            ? sprintf('E-mail #%d opnieuw verstuurd naar %s.', $id, implode(', ', MailParts::emails($to)))
            : sprintf('Opnieuw versturen van e-mail #%d is mislukt. Zie de nieuwe regel bovenaan de log.', $id));
    }

    public function handleCsv(): void
    {
        Ui::guard('nb_ocm_smtp_log_csv');
        $f = LogQuery::normalize(wp_unslash($_GET));
        [$start, $end] = LogQuery::range($f['from'], $f['to'], wp_timezone());
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="e-maillog-' . wp_date('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        echo "\xEF\xBB\xBF"; // BOM, zodat Excel UTF-8 herkent
        echo Export::csvLine(Export::csvColumns()); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV, geen HTML
        foreach ($this->repo->each($f, $start, $end) as $row) {
            echo Export::csvLine(Export::csvValues($row, (string) wp_date('Y-m-d H:i:s', (int) $row['created_at']))); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        exit;
    }

    public function handleEml(): void
    {
        Ui::guard('nb_ocm_smtp_log_eml');
        $id = (int) ($_GET['id'] ?? 0);
        $row = $id > 0 ? $this->repo->find($id) : null;
        if ($row === null) {
            Ui::redirect('maillog', 'error', 'Deze e-mail staat niet (meer) in de log.');
        }
        nocache_headers();
        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="e-mail-' . $id . '.eml"');
        header('X-Content-Type-Options: nosniff');
        echo Export::eml($row); // phpcs:ignore WordPress.Security.EscapeOutput -- bestand, geen HTML
        exit;
    }
}
