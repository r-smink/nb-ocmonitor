<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Kant-en-klare schakelaars voor veelgebruikte aanpassingen, in plaats van losse code in
 * functions.php. Alleen deze vaste lijst; het panel kan geen eigen code sturen. Het panel zet ze
 * met tweaks.set (scope maintenance); de huidige stand staat in de inventaris.
 */
final class Tweaks
{
    public const OPTION = 'nb_ocm_tweaks';
    private const UPLOADS_MARKER = 'NB OC Monitor: geen PHP in uploads';

    /** key => [type, standaard] */
    public const DEFINITIONS = [
        'disable_comments' => ['bool', false],
        'hide_wp_version' => ['bool', false],
        'clean_head' => ['bool', false],
        'disable_emojis' => ['bool', false],
        'disable_embeds' => ['bool', false],
        'disable_file_edit' => ['bool', false],
        'generic_login_errors' => ['bool', false],
        'security_headers' => ['bool', false],
        'disable_app_passwords' => ['bool', false],
        'block_php_uploads' => ['bool', false],
        'hide_admin_bar' => ['bool', false],
        'heartbeat' => ['choice', 'default'],      // default, slow (60 s), off_frontend
        'revisions' => ['int', -1],               // -1 = WordPress-standaard, 0..50
    ];

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function normalize($value): array
    {
        $value = is_array($value) ? $value : [];
        $out = [];
        foreach (self::DEFINITIONS as $key => [$type, $default]) {
            $v = $value[$key] ?? $default;
            switch ($type) {
                case 'bool':
                    $out[$key] = (bool) $v;
                    break;
                case 'int':
                    $out[$key] = is_numeric($v) ? max(-1, min(50, (int) $v)) : $default;
                    break;
                default:
                    $out[$key] = in_array($v, ['default', 'slow', 'off_frontend'], true) ? $v : $default;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function current(): array
    {
        return self::normalize(get_option(self::OPTION));
    }

    public static function register(): void
    {
        $t = self::current();

        if ($t['disable_comments']) {
            add_filter('comments_open', '__return_false', 20);
            add_filter('pings_open', '__return_false', 20);
            add_filter('comments_array', '__return_empty_array', 20);
            add_action('admin_menu', static function (): void {
                remove_menu_page('edit-comments.php');
            });
            add_action('admin_init', static function (): void {
                global $pagenow;
                if ($pagenow === 'edit-comments.php') {
                    wp_safe_redirect(admin_url());
                    exit;
                }
                foreach (get_post_types() as $type) {
                    if (post_type_supports($type, 'comments')) {
                        remove_post_type_support($type, 'comments');
                        remove_post_type_support($type, 'trackbacks');
                    }
                }
            });
            add_action('wp_before_admin_bar_render', static function (): void {
                global $wp_admin_bar;
                if (is_object($wp_admin_bar)) {
                    $wp_admin_bar->remove_menu('comments');
                }
            });
        }
        if ($t['hide_wp_version']) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }
        if ($t['clean_head']) {
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'wp_shortlink_wp_head');
            remove_action('wp_head', 'rest_output_link_wp_head');
        }
        if ($t['disable_emojis']) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        }
        if ($t['disable_embeds']) {
            add_action('wp_footer', static function (): void {
                wp_deregister_script('wp-embed');
            });
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
        }
        if ($t['disable_file_edit'] && !defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }
        if ($t['generic_login_errors']) {
            add_filter('login_errors', static function ($error) {
                // Onze eigen meldingen (blokkade, 2FA) blijven staan; de rest verraadt niet of de gebruikersnaam bestaat.
                return is_string($error) && (strpos($error, 'inlogpogingen') !== false || strpos($error, 'geblokkeerd') !== false) ? $error : '<strong>Fout:</strong> de gebruikersnaam of het wachtwoord klopt niet.';
            });
        }
        if ($t['security_headers']) {
            add_action('send_headers', static function (): void {
                if (!headers_sent()) {
                    header('X-Content-Type-Options: nosniff');
                    header('X-Frame-Options: SAMEORIGIN');
                    header('Referrer-Policy: strict-origin-when-cross-origin');
                    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
                }
            });
        }
        if ($t['disable_app_passwords']) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }
        if ($t['hide_admin_bar']) {
            add_filter('show_admin_bar', static function ($show) {
                return current_user_can('edit_posts') ? $show : false;
            });
        }
        if ($t['heartbeat'] === 'slow') {
            add_filter('heartbeat_settings', static function (array $settings): array {
                $settings['interval'] = 60;
                return $settings;
            });
        } elseif ($t['heartbeat'] === 'off_frontend') {
            add_action('init', static function (): void {
                if (!is_admin()) {
                    wp_deregister_script('heartbeat');
                }
            }, 1);
        }
        if ($t['revisions'] >= 0) {
            $keep = (int) $t['revisions'];
            add_filter('wp_revisions_to_keep', static function () use ($keep): int {
                return $keep;
            });
        }
    }

    /**
     * tweaks.set: alleen bekende sleutels, gecontroleerd. Wat niet wordt meegestuurd blijft zoals het is.
     *
     * @param array<string, mixed> $args {tweaks: array<string, mixed>}
     * @return array<string, mixed>
     */
    public static function set(array $args, EventRecorder $recorder): array
    {
        if (array_diff(array_keys($args), ['tweaks']) !== [] || !is_array($args['tweaks'] ?? null)) {
            throw new CommandError('bad_request', 'Alleen tweaks is toegestaan');
        }
        if (array_diff(array_keys($args['tweaks']), array_keys(self::DEFINITIONS)) !== []) {
            throw new CommandError('bad_request', 'Onbekende schakelaar');
        }
        $from = self::current();
        $to = self::normalize(array_merge($from, $args['tweaks']));
        update_option(self::OPTION, $to, true);
        self::syncUploadsHtaccess($to['block_php_uploads']);

        $changed = array_keys(array_filter($to, static function ($v, $k) use ($from) {
            return $from[$k] !== $v;
        }, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $recorder->record('ocm.tweaks_changed', implode(',', $changed));
        }

        return ['tweaks' => $to, 'changed' => $changed];
    }

    /** Apache: PHP-bestanden in uploads niet uitvoeren. Nginx negeert .htaccess (daar de serverconfig). */
    private static function syncUploadsHtaccess(bool $enabled): void
    {
        $uploads = wp_get_upload_dir();
        $file = rtrim((string) ($uploads['basedir'] ?? ''), '/') . '/.htaccess';
        if ($file === '/.htaccess') {
            return;
        }
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $pattern = '/\n?# BEGIN ' . preg_quote(self::UPLOADS_MARKER, '/') . '.*?# END ' . preg_quote(self::UPLOADS_MARKER, '/') . '\n?/s';
        $clean = (string) preg_replace($pattern, "\n", $current);
        $block = "# BEGIN " . self::UPLOADS_MARKER . "\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar)$\">\n  Require all denied\n</FilesMatch>\n# END " . self::UPLOADS_MARKER . "\n";
        $new = $enabled ? rtrim($clean) . "\n" . $block : rtrim($clean) . "\n";
        if (trim($new) === '') {
            if (is_file($file) && trim($current) !== '') {
                @unlink($file);
            }
            return;
        }
        if ($new !== $current) {
            @file_put_contents($file, ltrim($new));
        }
    }
}
