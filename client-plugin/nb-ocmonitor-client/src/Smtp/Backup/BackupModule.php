<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Backup;

use NextBuzz\OcMonitor\Client\Admin\Ui;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Smtp\Mailer;
use NextBuzz\OcMonitor\Client\Smtp\Options;
use NextBuzz\OcMonitor\Client\Smtp\Preflight;

/**
 * Backupverbinding. Faalt de primaire verbinding, dan vraagt de Log-interceptor via het filter
 * nb_ocm_smtp_try_backup om de mail opnieuw te versturen via de backup (docs/smtp-module.md §2).
 * Wordt aangeroepen vanuit Smtp\Module::register() als de module aan staat.
 */
final class BackupModule
{
    public const HEADER = 'X-NB-OCM-Connection: backup';
    /** Testmails en meldingsmails van de module zelf krijgen geen backup. */
    public const SKIP_HEADERS = ['x-nb-ocm-test', 'x-nb-ocm-alert'];

    private EventRecorder $recorder;
    /** Loopt er nu een backuppoging? Dan niet opnieuw (geen lussen). */
    private static bool $running = false;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    /** @param bool $active neemt de SMTP-module de verzending nu over (Module::state()['active']) */
    public function register(bool $active): void
    {
        if ($active) {
            add_filter('nb_ocm_smtp_try_backup', [$this, 'tryBackup'], 10, 3);
        }
        if (is_admin()) {
            add_action('admin_post_nb_ocm_smtp_backup_save', [$this, 'handleSave']);
            add_action('admin_post_nb_ocm_smtp_backup_check', [$this, 'handleCheck']);
        }
    }

    /**
     * Filter nb_ocm_smtp_try_backup.
     *
     * @param mixed $result null, of het resultaat van een eerdere callback
     * @param mixed $args de oorspronkelijke argumenten van wp_mail()
     * @param mixed $primaryError fout van de primaire verbinding (alleen ter informatie)
     * @return array{ok: bool, error: string}|null
     */
    public function tryBackup($result, $args = [], $primaryError = '')
    {
        if ($result !== null || self::$running || !is_array($args)) {
            return is_array($result) ? $result : null;
        }
        $mail = self::mailArgs($args);
        if ($mail === null || self::shouldSkip($mail['headers'])) {
            return null;
        }
        $backup = BackupOptions::get();
        if (!BackupOptions::isUsable($backup)) {
            return null;
        }

        $mailer = new Mailer(BackupOptions::mailerOptions($backup, Options::get()), BackupOptions::password());
        $error = '';
        $onFail = static function ($wpError) use (&$error): void {
            $error = is_wp_error($wpError) ? $wpError->get_error_message() : 'Onbekende fout';
        };
        $configure = static function ($phpmailer) use ($mailer): void {
            $mailer->configure($phpmailer);
        };
        self::$running = true;
        add_action('phpmailer_init', $configure, PHP_INT_MAX);
        add_action('wp_mail_failed', $onFail, PHP_INT_MAX);
        try {
            $ok = (bool) wp_mail($mail['to'], $mail['subject'], $mail['message'], self::withHeader($mail['headers']), $mail['attachments']);
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        } finally {
            remove_action('phpmailer_init', $configure, PHP_INT_MAX);
            remove_action('wp_mail_failed', $onFail, PHP_INT_MAX);
            self::$running = false;
        }
        if (!$ok && $error === '') {
            $error = 'wp_mail() gaf false terug zonder foutmelding.';
        }

        return ['ok' => $ok, 'error' => $ok ? '' : $error];
    }

    /** Loopt er nu een backuppoging (voor andere onderdelen die hun hooks willen overslaan)? */
    public static function isRunning(): bool
    {
        return self::$running;
    }

    /**
     * De argumenten van wp_mail(): associatief (zoals het filter wp_mail ze geeft) of op volgorde.
     *
     * @param array<int|string, mixed> $args
     * @return array{to: mixed, subject: string, message: string, headers: string|list<string>, attachments: mixed}|null
     */
    public static function mailArgs(array $args): ?array
    {
        if (array_key_exists('to', $args)) {
            $to = $args['to'];
            $subject = $args['subject'] ?? '';
            $message = $args['message'] ?? '';
            $headers = $args['headers'] ?? '';
            $attachments = $args['attachments'] ?? [];
        } else {
            [$to, $subject, $message, $headers, $attachments] = array_pad(array_values($args), 5, null);
        }
        if ($to === null || $to === '' || $to === []) {
            return null;
        }
        $headers = $headers ?? '';

        return [
            'to' => $to,
            'subject' => is_scalar($subject) ? (string) $subject : '',
            'message' => is_scalar($message) ? (string) $message : '',
            'headers' => is_array($headers) ? array_values(array_map('strval', array_filter($headers, 'is_scalar'))) : (is_scalar($headers) ? (string) $headers : ''),
            'attachments' => $attachments ?? [],
        ];
    }

    /** @param string|array<int, string> $headers */
    public static function shouldSkip($headers): bool
    {
        return array_intersect(self::headerNames($headers), self::SKIP_HEADERS) !== [];
    }

    /**
     * De headers met X-NB-OCM-Connection: backup erbij, in dezelfde vorm (string of lijst).
     *
     * @param string|array<int, string> $headers
     * @return string|array<int, string>
     */
    public static function withHeader($headers)
    {
        if (in_array('x-nb-ocm-connection', self::headerNames($headers), true)) {
            return $headers;
        }
        if (is_array($headers)) {
            $headers[] = self::HEADER;

            return $headers;
        }
        $headers = rtrim((string) $headers, "\r\n");

        return $headers === '' ? self::HEADER : $headers . "\r\n" . self::HEADER;
    }

    /**
     * @param string|array<int, string> $headers
     * @return list<string> header-namen in kleine letters
     */
    public static function headerNames($headers): array
    {
        $lines = is_array($headers) ? $headers : preg_split('/\r\n|\r|\n/', (string) $headers);
        $names = [];
        foreach ((array) $lines as $line) {
            $pos = strpos((string) $line, ':');
            if ($pos !== false && $pos > 0) {
                $names[] = strtolower(trim(substr((string) $line, 0, $pos)));
            }
        }

        return $names;
    }

    public function handleSave(): void
    {
        Ui::guard('nb_ocm_smtp_backup_save');
        $post = wp_unslash($_POST);
        $input = is_array($post['backup'] ?? null) ? $post['backup'] : [];
        $change = BackupOptions::save($input);
        $from = BackupOptions::describe($change['from']);
        $to = BackupOptions::describe($change['to']);
        $passChanged = $change['from']['pass'] !== $change['to']['pass'];
        if ($from !== $to || $passChanged || $change['from'] !== $change['to']) {
            $this->recorder->record('ocm.smtp_backup_changed', 'smtp_backup', $from, $to . ($passChanged ? ' (wachtwoord gewijzigd)' : ''));
        }
        $b = $change['to'];
        $warn = $b['enabled'] && !BackupOptions::isComplete($b)
            ? ' Let op: de backup is nog niet compleet (server, poort, gebruikersnaam en wachtwoord) en wordt pas gebruikt als alles is ingevuld.'
            : '';
        Ui::redirect('smtp', $warn === '' ? 'success' : 'error', 'Backupverbinding opgeslagen.' . $warn);
    }

    public function handleCheck(): void
    {
        Ui::guard('nb_ocm_smtp_backup_check');
        $b = BackupOptions::get();
        $result = Preflight::run(BackupOptions::mailerOptions($b, Options::get()), BackupOptions::password());
        set_transient(self::resultKey(), $result, 15 * MINUTE_IN_SECONDS);
        Ui::redirect('smtp', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'De backupverbinding werkt.' : 'De backupverbinding lukt niet. Zie de stappen bij Backupverbinding.');
    }

    /** @return array<string, mixed>|null resultaat van de laatste backupcheck van deze gebruiker (eenmalig) */
    public static function takeResult(): ?array
    {
        $result = get_transient(self::resultKey());
        delete_transient(self::resultKey());

        return is_array($result) ? $result : null;
    }

    private static function resultKey(): string
    {
        return 'nb_ocm_smtp_backup_result_' . get_current_user_id();
    }
}
