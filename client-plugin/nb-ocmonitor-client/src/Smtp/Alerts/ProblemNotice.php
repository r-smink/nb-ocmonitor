<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

use NextBuzz\OcMonitor\Client\Admin\Ui;

/**
 * Waarschuwing in WordPress zolang mail mislukt: een compacte adminmelding op alle beheerpagina's
 * (behalve onze eigen pagina, die heeft een eigen melding via inline()) en een rode "!" in de
 * adminbalk. Weg bij de eerstvolgende geslaagde mail; verbergen kan tot de volgende nieuwe fout.
 *
 * Optie nb_ocm_smtp_problem: {since: int, last: int, count: int, log_id: ?int}
 */
final class ProblemNotice
{
    public const OPTION = 'nb_ocm_smtp_problem';
    public const DISMISS_META = 'nb_ocm_smtp_problem_dismissed';
    public const DISMISS_ACTION = 'nb_ocm_smtp_problem_dismiss';

    public static function register(): void
    {
        add_action('admin_bar_menu', [self::class, 'adminBar'], 100);
        if (is_admin()) {
            add_action('admin_notices', [self::class, 'notice']);
            add_action('admin_post_' . self::DISMISS_ACTION, [self::class, 'handleDismiss']);
        }
    }

    /** @param array<string, mixed> $result */
    public static function track(array $result): void
    {
        if (!empty($result['is_test'])) {
            return;
        }
        $current = self::current();
        $next = self::next($current, $result, (int) ($result['time'] ?? 0) ?: time());
        if ($next === $current) {
            return;
        }
        if ($next === null) {
            delete_option(self::OPTION);
        } else {
            update_option(self::OPTION, $next, true);
        }
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    public static function next(?array $current, array $result, int $now): ?array
    {
        $status = (string) ($result['status'] ?? '');
        if ($status === 'sent') {
            return null;
        }
        if ($status !== 'failed') {
            return $current;
        }

        return [
            'since' => $current !== null ? (int) $current['since'] : $now,
            'last' => $now,
            'count' => ($current !== null ? (int) $current['count'] : 0) + 1,
            'log_id' => isset($result['log_id']) ? (int) $result['log_id'] : null,
        ];
    }

    /** @return array{since: int, last: int, count: int, log_id: ?int}|null */
    public static function current(): ?array
    {
        $problem = get_option(self::OPTION);
        if (!is_array($problem) || empty($problem['last'])) {
            return null;
        }

        return [
            'since' => (int) ($problem['since'] ?? 0),
            'last' => (int) $problem['last'],
            'count' => (int) ($problem['count'] ?? 1),
            'log_id' => isset($problem['log_id']) ? (int) $problem['log_id'] : null,
        ];
    }

    /** Verborgen door deze gebruiker en sindsdien geen nieuwe fout? */
    private static function dismissed(array $problem): bool
    {
        return (int) get_user_meta(get_current_user_id(), self::DISMISS_META, true) >= $problem['last'];
    }

    public static function notice(): void
    {
        if (!current_user_can(Ui::CAPABILITY) || (string) ($_GET['page'] ?? '') === Ui::SLUG) {
            return;
        }
        $problem = self::current();
        if ($problem === null || self::dismissed($problem)) {
            return;
        }
        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=' . self::DISMISS_ACTION), self::DISMISS_ACTION);
        printf(
            '<div class="notice notice-error"><p><strong>NB OC Monitor:</strong> Een of meer e-mails konden niet worden verstuurd%s. <a href="%s">Bekijk details</a> · <a href="%s">Verbergen</a></p></div>',
            esc_html($problem['count'] > 1 ? sprintf(' (%d× sinds %s)', $problem['count'], Payload::time($problem['since'])) : ''),
            esc_url(Ui::url('maillog')),
            esc_url($dismiss)
        );
    }

    /** @param \WP_Admin_Bar $bar */
    public static function adminBar($bar): void
    {
        if (!is_admin_bar_showing() || !current_user_can(Ui::CAPABILITY) || self::current() === null) {
            return;
        }
        $bar->add_node([
            'id' => 'nb-ocm-smtp-problem',
            'title' => '<span style="display:inline-block;min-width:18px;height:18px;line-height:18px;margin-top:7px;border-radius:9px;background:#E22830;color:#fff;font-weight:700;text-align:center;font-size:12px" aria-hidden="true">!</span>'
                . '<span class="screen-reader-text">E-mail mislukt</span>',
            'href' => Ui::url('maillog'),
            'meta' => ['title' => 'Een of meer e-mails konden niet worden verstuurd'],
        ]);
    }

    public static function handleDismiss(): void
    {
        Ui::guard(self::DISMISS_ACTION);
        $problem = self::current();
        if ($problem !== null) {
            update_user_meta(get_current_user_id(), self::DISMISS_META, $problem['last']);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** Melding op onze eigen pagina (tabs SMTP, E-maillog, Meldingen); ook als hij elders verborgen is. */
    public static function inline(): void
    {
        $problem = self::current();
        if ($problem === null) {
            return;
        }
        printf(
            '<div class="nbocm-alert is-crit" role="status"><strong>Een of meer e-mails konden niet worden verstuurd.</strong> %s <a href="%s">Bekijk details</a></div>',
            esc_html(sprintf('%d× sinds %s, laatste om %s. Deze melding verdwijnt bij de eerstvolgende geslaagde e-mail.', $problem['count'], Payload::time($problem['since']), Payload::time($problem['last']))),
            esc_url(Ui::url('maillog', $problem['log_id'] ? ['log' => $problem['log_id']] : []))
        );
    }
}
