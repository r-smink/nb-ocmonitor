<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

/**
 * Andere plugins die ook de verzending van WordPress-mail overnemen. Zolang zo'n plugin actief
 * is, neemt de SMTP-module de verzending niet over: twee plugins op phpmailer_init geeft
 * onvoorspelbaar gedrag.
 */
final class Conflicts
{
    /** plugin-bestand => naam */
    public const PLUGINS = [
        'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
        'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
        'fluent-smtp/fluent-smtp.php' => 'FluentSMTP',
        'post-smtp/postman-smtp.php' => 'Post SMTP',
        'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
        'smtp-mailer/main.php' => 'SMTP Mailer',
        'wp-smtp/wp-smtp.php' => 'WP SMTP',
        'gmail-smtp/main.php' => 'Gmail SMTP',
        'suremails/suremails.php' => 'SureMail',
        'gravitysmtp/gravitysmtp.php' => 'Gravity SMTP',
        'wp-ses/wp-ses.php' => 'WP Offload SES',
        'mailgun/mailgun.php' => 'Mailgun',
        'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
        'mailin/sendinblue.php' => 'Brevo',
        'postmark-approved-wordpress-plugin/postmark.php' => 'Postmark',
        'wp-mailster/wp-mailster.php' => 'WP Mailster',
        'site-mailer/site-mailer.php' => 'Site Mailer',
        'smtp2go/smtp2go-wordpress-plugin.php' => 'SMTP2GO',
    ];

    /** @return list<string> namen van actieve conflicterende plugins */
    public static function active(): array
    {
        $active = (array) get_option('active_plugins', []);

        return self::detect($active);
    }

    /**
     * @param array<int|string, mixed> $activePlugins
     * @return list<string>
     */
    public static function detect(array $activePlugins): array
    {
        $names = [];
        foreach ($activePlugins as $file) {
            if (is_string($file) && isset(self::PLUGINS[$file])) {
                $names[] = self::PLUGINS[$file];
            }
        }

        return $names;
    }
}
