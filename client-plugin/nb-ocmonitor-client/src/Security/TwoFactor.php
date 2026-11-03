<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Security;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Tweestapsverificatie afdwingen voor beheerders (of ook redacteuren). Wie al een 2FA-plugin
 * gebruikt (Two Factor, Wordfence Login Security, WP 2FA) merkt niets; alle anderen krijgen na hun
 * wachtwoord een code van 6 cijfers per e-mail. Noodstop: define('NB_OCM_DISABLE_2FA', true) in
 * wp-config.php of `wp nb-ocm login-security --two-factor=off`.
 */
final class TwoFactor
{
    public const OPTION = 'nb_ocm_two_factor';
    public const MODES = ['off' => 'Uit', 'admins' => 'Beheerders', 'editors' => 'Beheerders en redacteuren'];

    private const PENDING_COOKIE = 'nb_ocm_2fa';
    private const TRUSTED_COOKIE = 'nb_ocm_2fa_trusted';
    private const CODE_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public static function mode(): string
    {
        $mode = get_option(self::OPTION);

        return is_string($mode) && isset(self::MODES[$mode]) ? $mode : 'off';
    }

    /** @return array{from: string, to: string} */
    public static function setMode(string $mode): array
    {
        $from = self::mode();
        update_option(self::OPTION, isset(self::MODES[$mode]) ? $mode : 'off', false);

        return ['from' => $from, 'to' => self::mode()];
    }

    public static function disabledByConstant(): bool
    {
        return defined('NB_OCM_DISABLE_2FA') && NB_OCM_DISABLE_2FA;
    }

    /** Welke 2FA de gebruiker heeft: een plugin, onze e-mailcode, of null (geen). */
    public static function providerFor(\WP_User $user): ?string
    {
        if (class_exists('Two_Factor_Core') && method_exists('Two_Factor_Core', 'is_user_using_two_factor') && \Two_Factor_Core::is_user_using_two_factor($user->ID)) {
            return 'two-factor';
        }
        if (class_exists('\WordfenceLS\Controller_Users')) {
            $controller = \WordfenceLS\Controller_Users::shared();
            if (method_exists($controller, 'has_2fa_active') && $controller->has_2fa_active($user)) {
                return 'wordfence';
            }
        }
        $wp2fa = get_user_meta($user->ID, 'wp_2fa_enabled_methods', true);
        if (is_string($wp2fa) && $wp2fa !== '' || is_array($wp2fa) && $wp2fa !== []) {
            return 'wp-2fa';
        }
        $other = apply_filters('nb_ocm_user_two_factor', null, $user);
        if (is_string($other) && $other !== '') {
            return $other;
        }

        return self::applies($user) ? 'email' : null;
    }

    /** Valt deze gebruiker onder het beleid (los van een eventuele 2FA-plugin)? */
    public static function applies(\WP_User $user): bool
    {
        $mode = self::mode();
        if ($mode === 'off' || self::disabledByConstant()) {
            return false;
        }

        return user_can($user, 'manage_options') || ($mode === 'editors' && user_can($user, 'edit_others_posts'));
    }

    public function register(): void
    {
        if (self::mode() === 'off' || self::disabledByConstant()) {
            return;
        }
        add_action('wp_login', [$this, 'onLogin'], 1, 2);
        add_action('login_form_nb_ocm_2fa', [$this, 'handleForm']);
        add_filter('login_message', static function ($message) {
            $state = isset($_GET['nb_ocm_2fa']) ? (string) $_GET['nb_ocm_2fa'] : '';
            $texts = ['expired' => 'De inlogcode is verlopen. Log opnieuw in.', 'blocked' => 'Te vaak een verkeerde code. Log opnieuw in voor een nieuwe code.'];

            return isset($texts[$state]) ? $message . '<p class="message">' . esc_html($texts[$state]) . '</p>' : $message;
        });
    }

    /**
     * Wachtwoord klopt: de sessie nog niet toestaan, maar eerst een code mailen.
     *
     * @param \WP_User $user
     */
    public function onLogin(string $login, $user): void
    {
        if (!$user instanceof \WP_User || !self::applies($user) || self::providerFor($user) !== 'email') {
            return;
        }
        $trusted = isset($_COOKIE[self::TRUSTED_COOKIE . '_' . $user->ID]) ? (string) $_COOKIE[self::TRUSTED_COOKIE . '_' . $user->ID] : '';
        if ($trusted !== '' && TrustedDevice::valid($trusted, $user->ID, $user->user_pass, time(), wp_salt('auth'))) {
            return;
        }

        wp_clear_auth_cookie();
        $token = bin2hex(random_bytes(16));
        $code = (string) random_int(100000, 999999);
        set_transient(self::key($token), [
            'user_id' => $user->ID,
            'code' => hash_hmac('sha256', $code, wp_salt('auth')),
            'expires' => time() + self::CODE_MINUTES * 60,
            'attempts' => 0,
            'remember' => !empty($_POST['rememberme']),
            'redirect' => isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : admin_url(),
        ], self::CODE_MINUTES * 60);
        setcookie(self::PENDING_COOKIE, $token, ['expires' => time() + self::CODE_MINUTES * 60, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);

        wp_mail(
            $user->user_email,
            sprintf('[%s] Je inlogcode: %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $code),
            sprintf("Je inlogcode voor %s is: %s\n\nDe code is %d minuten geldig. Heb je niet zelf geprobeerd in te loggen? Wijzig dan direct je wachtwoord.", home_url(), $code, self::CODE_MINUTES)
        );

        wp_safe_redirect(add_query_arg('action', 'nb_ocm_2fa', wp_login_url()));
        exit;
    }

    public function handleForm(): void
    {
        $token = isset($_COOKIE[self::PENDING_COOKIE]) ? preg_replace('/[^a-f0-9]/', '', (string) $_COOKIE[self::PENDING_COOKIE]) : '';
        $pending = $token !== '' ? get_transient(self::key($token)) : false;
        if (!is_array($pending) || $pending['expires'] < time()) {
            wp_safe_redirect(add_query_arg('nb_ocm_2fa', 'expired', wp_login_url()));
            exit;
        }
        $user = get_user_by('id', (int) $pending['user_id']);
        if (!$user) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $code = isset($_POST['nb_ocm_code']) ? preg_replace('/\D/', '', (string) $_POST['nb_ocm_code']) : '';
            $pending['attempts']++;
            if (hash_equals($pending['code'], hash_hmac('sha256', (string) $code, wp_salt('auth')))) {
                delete_transient(self::key($token));
                setcookie(self::PENDING_COOKIE, '', ['expires' => time() - 3600, 'path' => COOKIEPATH ?: '/']);
                if (!empty($_POST['nb_ocm_trust'])) {
                    $expires = time() + TrustedDevice::DAYS * DAY_IN_SECONDS;
                    setcookie(self::TRUSTED_COOKIE . '_' . $user->ID, TrustedDevice::make($user->ID, $user->user_pass, $expires, wp_salt('auth')), [
                        'expires' => $expires, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
                    ]);
                }
                wp_set_auth_cookie($user->ID, (bool) $pending['remember']);
                $this->recorder->record('user.login', $user->user_login, null, null, 'success', ['two_factor' => 'email'], 'wp-login');
                wp_safe_redirect(wp_validate_redirect($pending['redirect'], admin_url()));
                exit;
            }
            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                delete_transient(self::key($token));
                $this->recorder->record('login.two_factor_failed', $user->user_login, null, null, 'failed', ['attempts' => $pending['attempts']], 'wp-login');
                wp_safe_redirect(add_query_arg('nb_ocm_2fa', 'blocked', wp_login_url()));
                exit;
            }
            set_transient(self::key($token), $pending, max(60, $pending['expires'] - time()));
            $error = 'Deze code klopt niet. Probeer het opnieuw.';
        }

        $email = (string) $user->user_email;
        $masked = substr($email, 0, 1) . '***' . substr($email, (int) strpos($email, '@'));
        login_header('Verificatiecode', '<p class="message">We hebben een code van 6 cijfers gemaild naar ' . esc_html($masked) . '.</p>', $error !== '' ? new \WP_Error('nb_ocm_2fa', $error) : null);
        echo '<form name="nb_ocm_2fa" method="post" action="' . esc_url(add_query_arg('action', 'nb_ocm_2fa', wp_login_url())) . '">';
        echo '<p><label for="nb_ocm_code">Code</label><input type="text" name="nb_ocm_code" id="nb_ocm_code" class="input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus></p>';
        echo '<p class="forgetmenot"><label><input name="nb_ocm_trust" type="checkbox" value="1"> Dit apparaat ' . TrustedDevice::DAYS . ' dagen vertrouwen</label></p>';
        echo '<p class="submit"><input type="submit" class="button button-primary button-large" value="Inloggen"></p></form>';
        login_footer();
        exit;
    }

    private static function key(string $token): string
    {
        return 'nb_ocm_2fa_' . hash('sha256', $token);
    }
}
