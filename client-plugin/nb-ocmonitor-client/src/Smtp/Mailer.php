<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

/**
 * Zet de SMTP-instellingen op PHPMailer via de gewone WordPress-hooks (phpmailer_init, wp_mail_from,
 * wp_mail_from_name). PHPMailer wordt niet vervangen, zodat dit niet botst met hoe WordPress mailt.
 */
final class Mailer
{
    /** @var array<string, mixed> */
    private array $options;
    private ?string $password;
    /** Afzender die WordPress of een plugin wilde gebruiken, als we die vervangen hebben. */
    private ?string $originalFrom = null;
    /** @var callable|null fn(string $line): void, alleen tijdens een testmail */
    private static $debugSink = null;
    /** Tijdelijk niet via SMTP maar via de standaard mail() van WordPress (meldingsmail als SMTP stuk is). */
    private static bool $bypass = false;

    /** @param array<string, mixed> $options */
    public function __construct(array $options, ?string $password)
    {
        $this->options = $options;
        $this->password = $password;
    }

    public function register(): void
    {
        add_filter('wp_mail_from', [$this, 'filterFrom'], PHP_INT_MAX);
        add_filter('wp_mail_from_name', [$this, 'filterFromName'], PHP_INT_MAX);
        add_action('phpmailer_init', [$this, 'configure'], PHP_INT_MAX - 10);
    }

    /** Tijdens een testmail de SMTP-dialoog opvangen (PHPMailer SMTPDebug 3). */
    public static function captureDebug(?callable $sink): void
    {
        self::$debugSink = $sink;
    }

    /** Zet SMTP tijdelijk uit voor wp_mail(): dan verstuurt WordPress met PHP mail(). Altijd terugzetten (try/finally). */
    public static function bypass(bool $on): void
    {
        self::$bypass = $on;
    }

    public static function bypassed(): bool
    {
        return self::$bypass;
    }

    /** @param mixed $from */
    public function filterFrom($from): string
    {
        $from = is_string($from) ? $from : '';
        $configured = (string) $this->options['from_email'];
        if ($configured === '') {
            return $from;
        }
        if (self::isDefaultWpFrom($from) || $this->options['force_from_email']) {
            if ($from !== '' && !self::isDefaultWpFrom($from) && strcasecmp($from, $configured) !== 0) {
                $this->originalFrom = $from;
            }

            return $configured;
        }

        return $from;
    }

    /** @param mixed $name */
    public function filterFromName($name): string
    {
        $name = is_string($name) ? $name : '';
        $configured = (string) $this->options['from_name'];
        if ($configured === '') {
            return $name;
        }

        return $this->options['force_from_name'] || $name === '' || $name === 'WordPress' ? $configured : $name;
    }

    /** @param \PHPMailer\PHPMailer\PHPMailer $mailer */
    public function configure($mailer): void
    {
        if (self::$bypass) {
            return; // wp_mail() heeft PHPMailer al op isMail() gezet
        }
        $o = $this->options;
        $mailer->isSMTP();
        $mailer->Host = (string) $o['host'];
        $mailer->Port = (int) $o['port'];
        $mailer->SMTPSecure = $o['encryption'] === 'none' ? '' : (string) $o['encryption'];
        // Zonder versleuteling probeert PHPMailer STARTTLS als de server dat aanbiedt; dat kan uit.
        $mailer->SMTPAutoTLS = $o['encryption'] === 'tls' || (bool) $o['autotls'];
        $mailer->SMTPAuth = (bool) $o['auth'];
        if ($o['auth']) {
            $mailer->Username = (string) $o['user'];
            $mailer->Password = (string) $this->password;
        }
        $mailer->Timeout = 30;
        if ($o['return_path']) {
            $mailer->Sender = $mailer->From;
        }
        // De afzender van een formulier of plugin blijft bereikbaar via Antwoorden.
        if ($this->originalFrom !== null && $mailer->getReplyToAddresses() === []) {
            $mailer->addReplyTo($this->originalFrom);
        }
        $this->originalFrom = null;

        if (self::$debugSink !== null) {
            $sink = self::$debugSink;
            $mailer->SMTPDebug = 3;
            $mailer->Debugoutput = static function ($line) use ($sink): void {
                $sink(rtrim((string) $line));
            };
        } else {
            $mailer->SMTPDebug = 0;
        }
    }

    public static function isDefaultWpFrom(string $from): bool
    {
        return $from === '' || stripos($from, 'wordpress@') === 0;
    }
}
