<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

/**
 * Bouwstenen voor de clientpagina in Next Buzz-stijl (assets/admin.css): kop met tabbladen,
 * kaarten, schakelaars, statuslabels. Plus de gedeelde afhandeling van formulieren.
 */
final class Ui
{
    public const SLUG = 'nb-ocmonitor';
    public const CAPABILITY = 'manage_options';

    public static function url(string $tab = 'overzicht', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('admin.php'));
    }

    public static function guard(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Geen toegang.', 403);
        }
        check_admin_referer($action);
    }

    /** @return never */
    public static function redirect(string $tab, string $type, string $message): void
    {
        set_transient(self::noticeKey(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(self::url($tab));
        exit;
    }

    /** @return array{type: string, message: string}|null */
    public static function takeNotice(): ?array
    {
        $notice = get_transient(self::noticeKey());
        delete_transient(self::noticeKey());

        return is_array($notice) ? $notice : null;
    }

    private static function noticeKey(): string
    {
        return 'nb_ocm_notice_' . get_current_user_id();
    }

    /**
     * @param array<string, string> $tabs slug => label
     * @param list<array{0: string, 1: string}> $chips [[label, ok|warn|crit|muted], ...]
     */
    public static function header(string $title, string $subtitle, array $tabs, string $active, array $chips = []): void
    {
        echo '<div class="nbocm-hd"><div class="nbocm-hd-in">';
        echo '<div class="nbocm-brand"><span class="nbocm-logo" aria-hidden="true">NB</span><div>';
        echo '<div class="nbocm-eyebrow">Next Buzz</div>';
        printf('<h1>%s</h1><p>%s</p>', esc_html($title), esc_html($subtitle));
        echo '</div></div>';
        if ($chips !== []) {
            echo '<div class="nbocm-hd-chips">';
            foreach ($chips as [$label, $tone]) {
                self::pill($label, $tone, true);
            }
            echo '</div>';
        }
        echo '</div><nav class="nbocm-tabs" aria-label="Onderdelen">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="%s"%s>%s</a>',
                esc_url(self::url($slug)),
                $slug === $active ? 'is-active' : '',
                $slug === $active ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</nav></div>';
    }

    /** @param array{type: string, message: string}|null $notice */
    public static function notice(?array $notice): void
    {
        if ($notice === null) {
            return;
        }
        $tone = $notice['type'] === 'success' ? 'ok' : 'crit';
        printf('<div class="nbocm-alert is-%s" role="status">%s</div>', $tone, esc_html($notice['message']));
    }

    public static function cardOpen(string $title, string $hint = '', string $extraClass = ''): void
    {
        printf('<section class="nbocm-card %s"><header class="nbocm-card-hd"><h2>%s</h2>', esc_attr($extraClass), esc_html($title));
        if ($hint !== '') {
            printf('<p>%s</p>', esc_html($hint));
        }
        echo '</header><div class="nbocm-card-bd">';
    }

    public static function cardClose(): void
    {
        echo '</div></section>';
    }

    public static function pill(string $label, string $tone = 'muted', bool $onDark = false): void
    {
        printf('<span class="nbocm-pill is-%s%s">%s</span>', esc_attr($tone), $onDark ? ' on-dark' : '', esc_html($label));
    }

    /** Schakelaar (checkbox) met label en uitleg. */
    public static function toggle(string $name, bool $checked, string $label, string $help = '', bool $disabled = false): void
    {
        $id = 'nbocm-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
        printf(
            '<div class="nbocm-toggle"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s><label for="%1$s"><span class="nbocm-switch" aria-hidden="true"></span><span><strong>%5$s</strong>%6$s</span></label></div>',
            esc_attr($id),
            esc_attr($name),
            $checked ? ' checked' : '',
            $disabled ? ' disabled' : '',
            esc_html($label),
            $help !== '' ? '<small>' . esc_html($help) . '</small>' : ''
        );
    }

    /**
     * Rij met label en willekeurige (al ge-escapete) invoer-HTML.
     */
    public static function field(string $label, string $inputHtml, string $help = '', string $for = ''): void
    {
        echo '<div class="nbocm-field">';
        printf($for !== '' ? '<label for="%2$s">%1$s</label>' : '<span class="nbocm-label">%1$s</span>', esc_html($label), esc_attr($for));
        echo '<div>' . $inputHtml; // phpcs:ignore WordPress.Security.EscapeOutput -- door de aanroeper ge-escapet
        if ($help !== '') {
            printf('<small>%s</small>', wp_kses($help, ['code' => [], 'strong' => []]));
        }
        echo '</div></div>';
    }

    /** @param list<array{0: string, 1: string}> $rows [label, waarde-HTML] */
    public static function definitions(array $rows): void
    {
        echo '<dl class="nbocm-dl">';
        foreach ($rows as [$label, $value]) {
            printf('<dt>%s</dt><dd>%s</dd>', esc_html($label), $value); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</dl>';
    }

    public static function formOpen(string $action): void
    {
        printf('<form method="post" action="%s" class="nbocm-form">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field($action);
        printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
    }

    public static function button(string $label, string $variant = 'primary', string $name = ''): void
    {
        printf('<button type="submit" class="nbocm-btn is-%s"%s>%s</button>', esc_attr($variant), $name !== '' ? ' name="' . esc_attr($name) . '" value="1"' : '', esc_html($label));
    }
}
