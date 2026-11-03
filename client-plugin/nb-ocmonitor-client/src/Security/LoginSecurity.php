<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Security;

use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Inlogbeveiliging: tijdelijke blokkade per IP na te veel mislukte pogingen, XML-RPC-login uit, en
 * gebruikersnamen afschermen voor anonieme bezoekers (REST /wp/v2/users, ?author=N, users-sitemap).
 *
 * Instellingen staan op de site zelf (WP-admin of WP-CLI). Inloggen via wp-login.php, de
 * WordPress-app met een applicatiewachtwoord en de blokeditor blijven gewoon werken.
 */
final class LoginSecurity
{
    public const OPTION = 'nb_ocm_login_security';
    private const LOCKOUTS_OPTION = 'nb_ocm_lockouts';
    private const MAX_TRACKED = 1000;
    private const ESCALATE_AFTER = 3;
    private const ESCALATED_MINUTES = 1440;

    public const DEFAULTS = [
        'lockout' => true,
        'max_attempts' => 5,
        'window_minutes' => 15,
        'lockout_minutes' => 30,
        'allowlist' => [],
        'xmlrpc' => 'auto',
        'hide_users' => true,
    ];

    public const XMLRPC_MODES = [
        'auto' => 'Uit, behalve als Jetpack actief is',
        'off' => 'Uit',
        'on' => 'Aan',
    ];

    private EventRecorder $recorder;
    private ?string $ip = null;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    /**
     * @param mixed $value
     * @return array{lockout: bool, max_attempts: int, window_minutes: int, lockout_minutes: int, allowlist: string[], xmlrpc: string, hide_users: bool}
     */
    public static function normalize($value): array
    {
        $value = is_array($value) ? $value : [];
        $int = static function (string $key, int $min, int $max) use ($value): int {
            $v = $value[$key] ?? self::DEFAULTS[$key];
            return is_numeric($v) ? max($min, min($max, (int) $v)) : self::DEFAULTS[$key];
        };
        $allow = $value['allowlist'] ?? [];
        if (is_string($allow)) {
            $allow = preg_split('/[\s,]+/', $allow) ?: [];
        }
        $allow = array_values(array_unique(array_filter(array_map('trim', array_filter((array) $allow, 'is_string')), [ClientIp::class, 'isValidRange'])));

        return [
            'lockout' => array_key_exists('lockout', $value) ? (bool) $value['lockout'] : self::DEFAULTS['lockout'],
            'max_attempts' => $int('max_attempts', 3, 50),
            'window_minutes' => $int('window_minutes', 1, 1440),
            'lockout_minutes' => $int('lockout_minutes', 1, 1440),
            'allowlist' => array_slice($allow, 0, 100),
            'xmlrpc' => isset($value['xmlrpc']) && is_string($value['xmlrpc']) && isset(self::XMLRPC_MODES[$value['xmlrpc']]) ? $value['xmlrpc'] : self::DEFAULTS['xmlrpc'],
            'hide_users' => array_key_exists('hide_users', $value) ? (bool) $value['hide_users'] : self::DEFAULTS['hide_users'],
        ];
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return self::normalize(get_option(self::OPTION));
    }

    /**
     * @param array<string, mixed> $config
     * @return array{from: array<string, mixed>, to: array<string, mixed>}
     */
    public static function save(array $config): array
    {
        $from = self::config();
        $to = self::normalize($config);
        update_option(self::OPTION, $to, false);

        return ['from' => $from, 'to' => $to];
    }

    /** Kort overzicht voor de inventaris in het panel. */
    public static function summary(): array
    {
        $config = self::config();
        $now = time();

        return [
            'lockout' => $config['lockout'],
            'max_attempts' => $config['max_attempts'],
            'lockout_minutes' => $config['lockout_minutes'],
            'allowlist_count' => count($config['allowlist']),
            'xmlrpc' => self::xmlrpcOff($config) ? 'off' : 'on',
            'xmlrpc_mode' => $config['xmlrpc'],
            'hide_users' => $config['hide_users'],
            'locked_now' => count(self::activeLockouts($now)),
        ];
    }

    public function register(): void
    {
        $config = self::config();

        add_action('wp_login_failed', [$this, 'onFailed'], 5);
        add_action('wp_login', [$this, 'onSuccess'], 5);
        if ($config['lockout']) {
            add_filter('authenticate', [$this, 'blockLocked'], 99);
        }
        if (self::xmlrpcOff($config)) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', static function (array $methods): array {
                unset($methods['system.multicall'], $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
                return $methods;
            });
        }
        if ($config['hide_users']) {
            add_filter('rest_pre_dispatch', [$this, 'blockUserEndpoints'], 10, 3);
            add_action('template_redirect', [$this, 'blockAuthorEnumeration'], 1);
            add_filter('wp_sitemaps_add_provider', static function ($provider, string $name) {
                return $name === 'users' ? false : $provider;
            }, 10, 2);
        }
    }

    /**
     * Na de wachtwoordcontrole: een geblokkeerd IP komt er niet in, ook niet met het juiste wachtwoord.
     *
     * @param mixed $user
     * @return mixed
     */
    public function blockLocked($user)
    {
        $until = $this->lockedUntil();
        if ($until === null) {
            return $user;
        }
        $minutes = max(1, (int) ceil(($until - time()) / 60));

        return new \WP_Error('nb_ocm_locked', sprintf(
            '<strong>Fout:</strong> te veel mislukte inlogpogingen. Probeer het over %d %s opnieuw.',
            $minutes,
            $minutes === 1 ? 'minuut' : 'minuten'
        ));
    }

    public function onFailed(): void
    {
        $config = self::config();
        $ip = $this->ip();
        $now = time();
        $blocked = $config['lockout'] && $this->lockedUntil() !== null;
        $lockedNow = false;

        if ($config['lockout'] && !$blocked && !ClientIp::inAny($ip, $config['allowlist'])) {
            $key = 'nb_ocm_lf_' . md5($ip);
            $state = get_transient($key);
            $state = is_array($state) && ($state['first'] ?? 0) > $now - $config['window_minutes'] * 60
                ? $state : ['count' => 0, 'first' => $now];
            $state['count']++;
            if ($state['count'] >= $config['max_attempts']) {
                delete_transient($key);
                $this->lock($ip, $config, $now);
                $lockedNow = true;
            } else {
                set_transient($key, $state, $config['window_minutes'] * 60);
            }
        }

        $this->recorder->countFailedLogin(self::channel(), $lockedNow, $blocked);
    }

    public function onSuccess(): void
    {
        delete_transient('nb_ocm_lf_' . md5($this->ip()));
    }

    /**
     * @param mixed $result
     * @param \WP_REST_Server $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public function blockUserEndpoints($result, $server, $request)
    {
        if ($result === null && !is_user_logged_in() && preg_match('#^/wp/v2/users(/|$)#', (string) $request->get_route())) {
            return new \WP_Error('rest_user_cannot_view', 'Gebruikersgegevens zijn alleen zichtbaar voor ingelogde gebruikers.', ['status' => 401]);
        }

        return $result;
    }

    public function blockAuthorEnumeration(): void
    {
        if (!is_user_logged_in() && isset($_GET['author']) && !is_admin()) {
            wp_safe_redirect(home_url('/'), 301);
            exit;
        }
    }

    /**
     * Actieve blokkades, voor WP-admin en WP-CLI.
     *
     * @return array<string, array{until: int, ip: string, locks: int}>
     */
    public static function activeLockouts(int $now): array
    {
        $all = get_option(self::LOCKOUTS_OPTION);

        return array_filter(is_array($all) ? $all : [], static function ($entry) use ($now): bool {
            return is_array($entry) && (int) ($entry['until'] ?? 0) > $now;
        });
    }

    /** @return int aantal opgeheven blokkades */
    public static function unlockAll(): int
    {
        $count = count(self::activeLockouts(time()));
        update_option(self::LOCKOUTS_OPTION, [], false);

        return $count;
    }

    /** @param array<string, mixed> $config */
    private static function xmlrpcOff(array $config): bool
    {
        if ($config['xmlrpc'] === 'auto') {
            return !class_exists('Jetpack') && !defined('JETPACK__VERSION');
        }

        return $config['xmlrpc'] === 'off';
    }

    /** @param array<string, mixed> $config */
    private function lock(string $ip, array $config, int $now): void
    {
        $hash = md5($ip);
        $all = get_option(self::LOCKOUTS_OPTION);
        $all = is_array($all) ? $all : [];
        // Oude blokkades (ouder dan een dag) opruimen; de lijst blijft begrensd bij een botnet.
        $all = array_filter($all, static function ($entry) use ($now): bool {
            return is_array($entry) && (int) ($entry['until'] ?? 0) > $now - 86400;
        });
        $locks = (int) ($all[$hash]['locks'] ?? 0) + 1;
        $minutes = $locks >= self::ESCALATE_AFTER ? self::ESCALATED_MINUTES : $config['lockout_minutes'];
        $all[$hash] = ['until' => $now + $minutes * 60, 'ip' => ClientIp::anonymize($ip), 'locks' => $locks];
        if (count($all) > self::MAX_TRACKED) {
            uasort($all, static function (array $a, array $b): int {
                return $b['until'] <=> $a['until'];
            });
            $all = array_slice($all, 0, self::MAX_TRACKED, true);
        }
        update_option(self::LOCKOUTS_OPTION, $all, false);
    }

    private function lockedUntil(): ?int
    {
        $ip = $this->ip();
        if (ClientIp::inAny($ip, self::config()['allowlist'])) {
            return null;
        }
        $entry = self::activeLockouts(time())[md5($ip)] ?? null;

        return $entry !== null ? (int) $entry['until'] : null;
    }

    private function ip(): string
    {
        return $this->ip ??= ClientIp::resolve($_SERVER);
    }

    private static function channel(): string
    {
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return 'xmlrpc';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }

        return ($GLOBALS['pagenow'] ?? '') === 'wp-login.php' ? 'wp-login' : 'other';
    }
}
