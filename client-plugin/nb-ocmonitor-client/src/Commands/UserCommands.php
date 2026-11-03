<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Security\TwoFactor;

/**
 * Gebruikersbeheer vanuit het panel (scope "users"). Bewust beperkt: het panel kan beheerders
 * zien, blokkeren, uitloggen, een wachtwoordreset afdwingen (de link gaat naar de gebruiker zelf)
 * en terugzetten naar een lagere rol. Het panel kan GEEN gebruikers aanmaken, geen wachtwoorden
 * kiezen en niemand beheerder maken. De laatste actieve beheerder kan niet geblokkeerd worden.
 */
final class UserCommands
{
    public const LOCK_META = 'nb_ocm_locked';
    public const LAST_LOGIN_META = 'nb_ocm_last_login';
    private const LISTED_ROLES = ['administrator', 'editor', 'shop_manager'];
    private const MAX_LISTED = 200;

    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('users.list', 'read', [$this, 'list']);
        $registry->add('users.lock', 'users', [$this, 'lock']);
        $registry->add('users.unlock', 'users', [$this, 'unlock']);
        $registry->add('users.logout', 'users', [$this, 'logout']);
        $registry->add('users.reset_password', 'users', [$this, 'resetPassword']);
        $registry->add('users.demote', 'users', [$this, 'demote']);
        $registry->add('security.two_factor', 'users', [$this, 'twoFactor']);
    }

    /** Hooks: laatste login bijhouden en geblokkeerde gebruikers overal weren (ook applicatiewachtwoorden). */
    public function register(): void
    {
        add_action('wp_login', static function (string $login, $user): void {
            if ($user instanceof \WP_User) {
                update_user_meta($user->ID, self::LAST_LOGIN_META, time());
            }
        }, 10, 2);
        add_filter('authenticate', static function ($user) {
            if ($user instanceof \WP_User && get_user_meta($user->ID, self::LOCK_META, true)) {
                return new \WP_Error('nb_ocm_user_locked', '<strong>Fout:</strong> dit account is geblokkeerd door de beheerder van de site.');
            }

            return $user;
        }, 100);
        add_filter('determine_current_user', static function ($userId) {
            return $userId && get_user_meta((int) $userId, self::LOCK_META, true) ? false : $userId;
        }, 99);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function list(array $args, CommandContext $context): array
    {
        UpdateArgs::none($args);
        $users = get_users(['role__in' => self::LISTED_ROLES, 'number' => self::MAX_LISTED, 'orderby' => 'login']);
        $counts = count_users();

        return [
            'users' => array_map(static function (\WP_User $user): array {
                $sessions = \WP_Session_Tokens::get_instance($user->ID)->get_all();

                return [
                    'id' => (int) $user->ID,
                    'login' => (string) $user->user_login,
                    'email' => (string) $user->user_email,
                    'name' => (string) $user->display_name,
                    'roles' => array_values((array) $user->roles),
                    'registered' => strtotime((string) $user->user_registered) ?: null,
                    'last_login' => ((int) get_user_meta($user->ID, self::LAST_LOGIN_META, true)) ?: null,
                    'locked' => (bool) get_user_meta($user->ID, self::LOCK_META, true),
                    'two_factor' => TwoFactor::providerFor($user),
                    'sessions' => count($sessions),
                ];
            }, $users),
            'total' => (int) $counts['total_users'],
            'by_role' => $counts['avail_roles'],
            'two_factor_mode' => TwoFactor::mode(),
            'two_factor_disabled_by_constant' => TwoFactor::disabledByConstant(),
        ];
    }

    /** @param array<string, mixed> $args */
    public function lock(array $args, CommandContext $context): array
    {
        $user = $this->user(UserArgs::userId($args));
        $this->guardLastAdmin($user);
        update_user_meta($user->ID, self::LOCK_META, time());
        \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
        $this->recorder->record('user.locked', $user->user_login);

        return ['user_id' => $user->ID, 'locked' => true];
    }

    /** @param array<string, mixed> $args */
    public function unlock(array $args, CommandContext $context): array
    {
        $user = $this->user(UserArgs::userId($args));
        delete_user_meta($user->ID, self::LOCK_META);
        $this->recorder->record('user.unlocked', $user->user_login);

        return ['user_id' => $user->ID, 'locked' => false];
    }

    /** @param array<string, mixed> $args */
    public function logout(array $args, CommandContext $context): array
    {
        $user = $this->user(UserArgs::userId($args));
        \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
        $this->recorder->record('user.logged_out', $user->user_login);

        return ['user_id' => $user->ID, 'sessions' => 0];
    }

    /**
     * Nieuw willekeurig wachtwoord (alle sessies vervallen) en een resetlink naar het e-mailadres
     * van de gebruiker zelf. Het panel ziet het wachtwoord nooit.
     *
     * @param array<string, mixed> $args
     */
    public function resetPassword(array $args, CommandContext $context): array
    {
        $user = $this->user(UserArgs::userId($args));
        wp_set_password(wp_generate_password(40, true, true), $user->ID);
        \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
        $sent = retrieve_password($user->user_login);
        $this->recorder->record('user.password_reset_forced', $user->user_login, null, null, $sent === true ? 'success' : 'failed');

        return ['user_id' => $user->ID, 'mail_sent' => $sent === true, 'message' => is_wp_error($sent) ? $sent->get_error_message() : null];
    }

    /** @param array<string, mixed> $args */
    public function demote(array $args, CommandContext $context): array
    {
        $a = UserArgs::demote($args);
        $user = $this->user($a['user_id']);
        $this->guardLastAdmin($user);
        $user->set_role($a['role']);
        \WP_Session_Tokens::get_instance($user->ID)->destroy_all();

        return ['user_id' => $user->ID, 'roles' => array_values((array) $user->roles)];
    }

    /** @param array<string, mixed> $args */
    public function twoFactor(array $args, CommandContext $context): array
    {
        $change = TwoFactor::setMode(UserArgs::twoFactorMode($args));
        if ($change['from'] !== $change['to']) {
            $this->recorder->record('ocm.two_factor_changed', 'two_factor', $change['from'], $change['to']);
        }

        return ['mode' => $change['to'], 'disabled_by_constant' => TwoFactor::disabledByConstant()];
    }

    private function user(int $id): \WP_User
    {
        $user = get_user_by('id', $id);
        if (!$user instanceof \WP_User) {
            throw new CommandError('not_found', 'Gebruiker bestaat niet', 404);
        }

        return $user;
    }

    /** Nooit de laatste niet-geblokkeerde beheerder blokkeren of terugzetten. */
    private function guardLastAdmin(\WP_User $user): void
    {
        if (!in_array('administrator', (array) $user->roles, true)) {
            return;
        }
        $active = array_filter(get_users(['role' => 'administrator', 'fields' => 'ID']), static function ($id) use ($user): bool {
            return (int) $id !== (int) $user->ID && !get_user_meta((int) $id, self::LOCK_META, true);
        });
        if ($active === []) {
            throw new CommandError('last_admin', 'Dit is de laatste actieve beheerder van de site', 409);
        }
    }
}
