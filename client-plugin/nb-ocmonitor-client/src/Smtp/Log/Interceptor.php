<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

use NextBuzz\OcMonitor\Client\Smtp\Diagnose;

/**
 * Bezit de verzendstroom van wp_mail() (zie docs/smtp-module.md, punt 1 t/m 3):
 * wp_mail → log-rij 'pending'; phpmailer_init → afzender en inhoudstype; wp_mail_succeeded → 'sent';
 * wp_mail_failed → eerst de backup proberen, dan de definitieve status. Daarna altijd
 * do_action('nb_ocm_smtp_mail_result'), behalve bij meldingsmails van de module zelf.
 *
 * Fouten in de log mogen het versturen nooit hinderen: alles staat in try/catch.
 */
final class Interceptor
{
    public const RESULT_ACTION = 'nb_ocm_smtp_mail_result';
    public const BACKUP_FILTER = 'nb_ocm_smtp_try_backup';
    private const MAX_CONTENT = 1048576; // 1 MB

    /** True zolang de backup een poging doet: dan negeren we onze eigen hooks. */
    private static bool $nested = false;
    /** Log-ID van de mail die nu opnieuw verstuurd wordt (LogTab → Opnieuw versturen). */
    private static ?int $resentOf = null;

    private bool $active;
    /** @var array{enabled: bool, log_content: bool, retention_days: int} */
    private array $settings;
    private LogRepository $repo;
    /** @var array<string, mixed>|null de mail die nu onderweg is */
    private ?array $current = null;
    /** @var object|null PHPMailer van de huidige mail */
    private $mailer = null;

    /** @param array{enabled: bool, log_content: bool, retention_days: int} $settings */
    public function __construct(bool $active, array $settings, LogRepository $repo)
    {
        $this->active = $active;
        $this->settings = $settings;
        $this->repo = $repo;
    }

    public static function isNested(): bool
    {
        return self::$nested;
    }

    /**
     * Verstuurt opnieuw via $send (die wp_mail() aanroept); de nieuwe log-rij krijgt resent_of = $id.
     *
     * @param callable(): bool $send
     */
    public static function resend(int $id, callable $send): bool
    {
        self::$resentOf = $id;
        try {
            return (bool) $send();
        } finally {
            self::$resentOf = null;
        }
    }

    public function register(): void
    {
        add_filter('wp_mail', [$this, 'onWpMail'], PHP_INT_MAX);
        add_filter('pre_wp_mail', [$this, 'onPreWpMail'], PHP_INT_MAX, 2);
        add_action('phpmailer_init', [$this, 'onPhpmailerInit'], PHP_INT_MAX);
        add_action('wp_mail_succeeded', [$this, 'onSucceeded'], PHP_INT_MAX);
        add_action('wp_mail_failed', [$this, 'onFailed'], PHP_INT_MAX);
    }

    /**
     * @param mixed $atts
     * @return mixed
     */
    public function onWpMail($atts)
    {
        if (self::$nested || !is_array($atts)) {
            return $atts;
        }
        try {
            // Een vorige mail zonder resultaat blijft 'pending'; de dagelijkse opruiming markeert hem.
            $this->current = null;
            $this->mailer = null;
            $headers = MailParts::parseHeaders($atts['headers'] ?? '');
            $to = MailParts::addresses($atts['to'] ?? '');
            $subject = is_scalar($atts['subject'] ?? null) ? (string) $atts['subject'] : '';
            $message = is_scalar($atts['message'] ?? null) ? (string) $atts['message'] : '';
            $initiator = Initiator::detect();

            $this->current = [
                'id' => null,
                'args' => [$atts['to'] ?? '', $atts['subject'] ?? '', $atts['message'] ?? '', $atts['headers'] ?? '', $atts['attachments'] ?? []],
                'to' => MailParts::emails($to),
                'subject' => $subject,
                'initiator' => $initiator['name'],
                'is_test' => $headers['test'],
                'is_alert' => $headers['alert'],
                'via_backup' => $headers['connection'] === 'backup',
                'from' => $headers['from'],
                'content_type' => $headers['content_type'],
            ];

            if ($this->settings['enabled']) {
                $now = time();
                $row = [
                    'created_at' => $now,
                    'updated_at' => $now,
                    'status' => 'pending',
                    'connection' => $this->connection(),
                    'is_test' => $headers['test'] ? 1 : 0,
                    'is_alert' => $headers['alert'] ? 1 : 0,
                    'to_email' => MailParts::clip(implode(', ', $to), 5000),
                    'cc' => MailParts::clip(implode(', ', $headers['cc']), 5000),
                    'bcc' => MailParts::clip(implode(', ', $headers['bcc']), 5000),
                    'from_email' => MailParts::clip($headers['from'], 255),
                    'subject' => MailParts::clip(MailParts::oneLine($subject), 1000),
                    'headers' => MailParts::clip(implode("\n", $headers['lines']), 20000),
                    'content_type' => MailParts::clip($headers['content_type'], 64),
                    'attachments' => (string) json_encode(MailParts::attachmentNames($atts['attachments'] ?? [])),
                    'initiator' => MailParts::clip($initiator['name'], 191),
                    'initiator_file' => MailParts::clip($initiator['file'], 255),
                ];
                if ($this->settings['log_content']) {
                    $row['content'] = strlen($message) > self::MAX_CONTENT ? substr($message, 0, self::MAX_CONTENT) : $message;
                }
                if (self::$resentOf !== null) {
                    $row['resent_of'] = self::$resentOf;
                }
                $this->current['id'] = $this->repo->insert($row);
            }
        } catch (\Throwable $e) {
            // De log mag het versturen niet tegenhouden.
        }

        return $atts;
    }

    /**
     * Een plugin die pre_wp_mail gebruikt, neemt het versturen over; wp_mail_succeeded/failed komen dan niet.
     *
     * @param mixed $return
     * @param mixed $atts
     * @return mixed
     */
    public function onPreWpMail($return, $atts = null)
    {
        if ($return === null || self::$nested || $this->current === null) {
            return $return;
        }
        try {
            $ok = (bool) $return;
            $this->finish(
                $ok ? 'sent' : 'failed',
                'wordpress',
                $ok ? '' : 'Een andere plugin heeft het versturen overgenomen (pre_wp_mail) en meldt dat het mislukt is.',
                ''
            );
        } catch (\Throwable $e) {
            $this->current = null;
        }

        return $return;
    }

    /** @param object $phpmailer */
    public function onPhpmailerInit($phpmailer): void
    {
        if (self::$nested || $this->current === null || !is_object($phpmailer)) {
            return;
        }
        $this->mailer = $phpmailer;
        try {
            $from = (string) ($phpmailer->From ?? '');
            $name = (string) ($phpmailer->FromName ?? '');
            if ($from !== '') {
                $this->current['from'] = $name !== '' ? sprintf('%s <%s>', $name, $from) : $from;
            }
            $type = strtolower((string) ($phpmailer->ContentType ?? ''));
            if ($type !== '') {
                $this->current['content_type'] = $type;
            }
        } catch (\Throwable $e) {
            // negeren
        }
    }

    /** @param mixed $mailData */
    public function onSucceeded($mailData = null): void
    {
        if (self::$nested || $this->current === null) {
            return;
        }
        try {
            $this->finish('sent', $this->connection(), '', '');
        } catch (\Throwable $e) {
            $this->current = null;
        }
    }

    /** @param mixed $error WP_Error */
    public function onFailed($error = null): void
    {
        if (self::$nested || $this->current === null) {
            return;
        }
        try {
            $message = is_object($error) && method_exists($error, 'get_error_message') ? (string) $error->get_error_message() : '';
            if ($message === '') {
                $message = 'wp_mail() is mislukt zonder foutmelding.';
            }
            $backup = null;
            if (!$this->current['is_test'] && !$this->current['is_alert'] && !$this->current['via_backup']) {
                self::$nested = true;
                try {
                    $backup = apply_filters(self::BACKUP_FILTER, null, $this->current['args'], $message);
                } catch (\Throwable $e) {
                    $backup = ['ok' => false, 'error' => $e->getMessage()];
                } finally {
                    self::$nested = false;
                }
            }
            if (is_array($backup) && !empty($backup['ok'])) {
                $this->finish('sent', 'backup', $message, '');
            } else {
                $this->finish('failed', $this->connection(), $message, is_array($backup) ? (string) ($backup['error'] ?? '') : '');
            }
        } catch (\Throwable $e) {
            self::$nested = false;
            $this->current = null;
        }
    }

    /** Definitieve status vastleggen en het resultaat doorgeven. */
    private function finish(string $status, string $connection, string $error, string $backupError): void
    {
        $c = $this->current;
        $mailer = $this->mailer;
        $this->current = null;
        $this->mailer = null;
        if ($c === null) {
            return;
        }
        $diagnose = $error !== '' ? (string) (Diagnose::explain($error)['code'] ?? '') : '';
        $messageId = '';
        $sentHeaders = '';
        if ($status === 'sent') {
            [$messageId, $sentHeaders] = self::fromMailer($mailer);
        }

        if ($c['id'] !== null) {
            try {
                $data = [
                    'status' => $status,
                    'connection' => $connection,
                    'updated_at' => time(),
                    'from_email' => MailParts::clip((string) $c['from'], 255),
                    'content_type' => MailParts::clip((string) $c['content_type'], 64),
                    'error' => MailParts::clip($error, 5000),
                    'backup_error' => MailParts::clip($backupError, 5000),
                    'diagnose_code' => MailParts::clip($diagnose, 32),
                ];
                if ($messageId !== '') {
                    $data['message_id'] = MailParts::clip($messageId, 255);
                }
                if ($sentHeaders !== '') {
                    $data['sent_headers'] = MailParts::clip($sentHeaders, 20000);
                }
                $this->repo->update((int) $c['id'], $data);
            } catch (\Throwable $e) {
                // negeren
            }
        }

        if ($c['is_alert']) {
            return; // meldingsmail van de module zelf: geen resultaat, zodat er geen lus ontstaat
        }
        do_action(self::RESULT_ACTION, [
            'log_id' => $c['id'] !== null ? (int) $c['id'] : null,
            'status' => $status,
            'connection' => $connection,
            'to' => $c['to'],
            'subject' => (string) $c['subject'],
            'error' => $error,
            'backup_error' => $backupError,
            'diagnose_code' => $diagnose,
            'initiator' => (string) $c['initiator'],
            'is_test' => (bool) $c['is_test'],
            'time' => time(),
        ]);
    }

    /** primary als deze module verstuurt, anders wordpress; backup als de header dat zegt. */
    private function connection(): string
    {
        if ($this->current['via_backup'] ?? false) {
            return 'backup';
        }

        return $this->active ? 'primary' : 'wordpress';
    }

    /**
     * Message-ID en de verstuurde headers. Alleen na een geslaagde verzending: na een fout kan PHPMailer
     * nog de gegevens van een vorige mail bevatten.
     *
     * @param object|null $mailer
     * @return array{0: string, 1: string}
     */
    private static function fromMailer($mailer): array
    {
        if (!is_object($mailer)) {
            return ['', ''];
        }
        try {
            $id = method_exists($mailer, 'getLastMessageID') ? (string) $mailer->getLastMessageID() : '';
            $mime = method_exists($mailer, 'getSentMIMEMessage') ? (string) $mailer->getSentMIMEMessage() : '';
            $pos = strpos($mime, "\r\n\r\n");
            $headers = $pos !== false ? substr($mime, 0, $pos) : '';

            return [$id, str_replace("\r\n", "\n", $headers)];
        } catch (\Throwable $e) {
            return ['', ''];
        }
    }
}
