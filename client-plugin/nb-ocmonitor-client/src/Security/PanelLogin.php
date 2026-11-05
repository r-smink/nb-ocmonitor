<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Security;

use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\Commands\UserCommands;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Inloggen vanuit het panel (auth.login_link, scope users). Het panel vraagt een link voor een
 * bestaande gebruiker; de site maakt een token dat 60 seconden geldig is en één keer werkt.
 * Op de site zelf uit te zetten (WP-admin of `wp nb-ocm panel-login off`); het panel kan dat niet.
 */
final class PanelLogin
{
    public const OPTION = 'nb_ocm_panel_login';
    private const TTL = 60;

    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public static function enabled(): bool
    {
        return get_option(self::OPTION, '1') !== '0' && !(defined('NB_OCM_DISABLE_PANEL_LOGIN') && NB_OCM_DISABLE_PANEL_LOGIN);
    }

    public static function setEnabled(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0', false);
    }

    public function register(): void
    {
        add_action('login_form_nb_ocm_sso', [$this, 'handle']);
    }

    /**
     * @param array<string, mixed> $args {user_id: int, panel_user?: string}
     * @return array{url: string, expires: int, user: string}
     */
    public function link(array $args): array
    {
        if (array_diff(array_keys($args), ['user_id', 'panel_user']) !== []) {
            throw new CommandError('bad_request', 'Alleen user_id en panel_user zijn toegestaan');
        }
        if (!self::enabled()) {
            throw new CommandError('panel_login_disabled', 'Inloggen vanuit het panel staat uit op deze site', 403);
        }
        $id = $args['user_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new CommandError('bad_request', 'user_id moet een positief getal zijn');
        }
        $user = get_user_by('id', $id);
        if (!$user instanceof \WP_User) {
            throw new CommandError('not_found', 'Gebruiker bestaat niet', 404);
        }
        if (get_user_meta($user->ID, UserCommands::LOCK_META, true)) {
            throw new CommandError('user_locked', 'Deze gebruiker is geblokkeerd', 409);
        }
        $panelUser = is_string($args['panel_user'] ?? null) ? mb_substr(preg_replace('/[^\p{L}\p{N} .@_-]/u', '', $args['panel_user']), 0, 80) : '';
        $token = bin2hex(random_bytes(24));
        set_transient(self::key($token), ['user_id' => $user->ID, 'panel_user' => $panelUser, 'expires' => time() + self::TTL], self::TTL);

        return [
            'url' => add_query_arg(['action' => 'nb_ocm_sso', 'token' => $token], wp_login_url()),
            'expires' => time() + self::TTL,
            'user' => (string) $user->user_login,
        ];
    }

    public function handle(): void
    {
        $token = isset($_GET['token']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['token']) : '';
        $data = $token !== '' && strlen($token) === 48 ? get_transient(self::key($token)) : false;
        if ($token !== '') {
            delete_transient(self::key($token)); // altijd maar één keer
        }
        if (!self::enabled() || !is_array($data) || (int) $data['expires'] < time()) {
            wp_die(esc_html('Deze inloglink is verlopen of al gebruikt. Vraag in het panel een nieuwe aan.'), 'Inloggen', ['response' => 403]);
        }
        $user = get_user_by('id', (int) $data['user_id']);
        if (!$user instanceof \WP_User || get_user_meta($user->ID, UserCommands::LOCK_META, true)) {
            wp_die(esc_html('Deze gebruiker kan niet inloggen.'), 'Inloggen', ['response' => 403]);
        }
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        update_user_meta($user->ID, UserCommands::LAST_LOGIN_META, time());
        $this->recorder->record('user.login', $user->user_login, null, null, 'success', ['via' => 'panel', 'panel_user' => $data['panel_user']], 'nb-ocm');
        wp_safe_redirect(admin_url());
        exit;
    }

    private static function key(string $token): string
    {
        return 'nb_ocm_sso_' . hash('sha256', $token);
    }
}
