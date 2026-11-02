<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Events;

use NextBuzz\OcMonitor\Client\Settings;

/**
 * Legt elke wijziging aan de site vast, ongeacht wie of wat hem uitvoert (plan §2.7):
 * WP-admin, WP-CLI, WordPress-auto-updates, andere plugins, het panel of een wijziging buiten
 * WordPress om (via de versie-snapshot).
 *
 * Events worden per request gebufferd en bij shutdown in één keer opgeslagen en naar het panel gepusht.
 */
final class EventRecorder
{
    public const SOURCE_PANEL = 'nb-ocm';
    public const SOURCE_UNKNOWN = 'unknown';

    private const FAILED_LOGINS_OPTION = 'nb_ocm_failed_logins';
    private const CRITICAL_OPTIONS = ['siteurl', 'home', 'admin_email', 'users_can_register', 'default_role'];

    private EventStore $store;
    private EventPusher $pusher;
    private VersionSnapshot $snapshot;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];
    private ?string $jobId = null;
    private bool $autoUpdate = false;
    /** @var array<string, ?string> versie vóór een update, per snapshot-sleutel */
    private array $previousVersions = [];
    /** @var string[] snapshot-sleutels die in dit request via hooks zijn vastgelegd */
    private array $touchedKeys = [];

    public function __construct(EventStore $store, EventPusher $pusher, VersionSnapshot $snapshot)
    {
        $this->store = $store;
        $this->pusher = $pusher;
        $this->snapshot = $snapshot;
    }

    public function register(): void
    {
        add_filter('upgrader_pre_install', [$this, 'onPreInstall'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'onUpgraderComplete'], 10, 2);
        add_action('_core_updated_successfully', [$this, 'onCoreUpdated']);
        add_action('pre_auto_update', [$this, 'onPreAutoUpdate']);
        add_action('automatic_updates_complete', [$this, 'onAutoUpdatesComplete']);

        add_action('activated_plugin', [$this, 'onPluginActivated']);
        add_action('deactivated_plugin', [$this, 'onPluginDeactivated']);
        add_action('deleted_plugin', [$this, 'onPluginDeleted'], 10, 2);
        add_action('switch_theme', [$this, 'onThemeSwitched'], 10, 3);
        add_action('deleted_theme', [$this, 'onThemeDeleted'], 10, 2);

        add_action('user_register', [$this, 'onUserRegistered']);
        add_action('set_user_role', [$this, 'onUserRoleSet'], 10, 3);
        add_action('delete_user', [$this, 'onUserDeleted'], 10, 3);
        add_action('profile_update', [$this, 'onProfileUpdated'], 10, 2);
        add_action('wp_login', [$this, 'onLogin'], 10, 2);
        add_action('wp_login_failed', [$this, 'onLoginFailed']);

        foreach (self::CRITICAL_OPTIONS as $option) {
            add_action('update_option_' . $option, [$this, 'onCriticalOptionUpdated'], 10, 3);
        }

        add_action('shutdown', [$this, 'flush'], 1000);
    }

    /** Wordt door het endpoint gezet zolang een panel-command loopt (voor attributie). */
    public function setJob(?string $jobId): void
    {
        $this->jobId = $jobId;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(string $type, string $object, ?string $from = null, ?string $to = null, string $result = 'success', array $data = [], ?string $source = null): void
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); // bewust geen X-Forwarded-For: die is te vervalsen

        $this->buffer[] = [
            'time' => time(),
            'type' => $type,
            'object' => $object,
            'from' => $from,
            'to' => $to,
            'result' => $result,
            'source' => $source ?? $this->source(),
            'job_id' => $this->jobId,
            'user_id' => $user !== null ? (int) $user->ID : 0,
            'user_login' => $user !== null ? (string) $user->user_login : '',
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'data' => $data,
        ];
    }

    public function flush(): void
    {
        if ($this->touchedKeys !== []) {
            // Verwerk tegelijk wijzigingen die níet via een hook liepen (anders zou de nieuwe
            // snapshot een eerdere FTP-wijziging ongemerkt opnemen).
            $this->recordExternalChanges($this->touchedKeys);
            $this->touchedKeys = [];
        }
        if ($this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];
        $stored = $this->store->insertMany($events);

        $config = Settings::load();
        if ($config !== null) {
            $this->pusher->push($config, $stored);
        }
    }

    /**
     * Vergelijkt de versie-snapshot en registreert onverklaarde wijzigingen als extern.
     *
     * @param string[] $knownKeys
     */
    public function recordExternalChanges(array $knownKeys = []): int
    {
        $changes = $this->snapshot->detectChanges($knownKeys);
        foreach ($changes as $change) {
            $this->record('component.changed_externally', $change['key'], $change['from'], $change['to'], 'success', [], self::SOURCE_UNKNOWN);
        }

        return count($changes);
    }

    /** Uurlijks via cron: mislukte logins als samenvatting per uur, niet per poging. */
    public function flushFailedLoginSummaries(int $now): void
    {
        $counts = get_option(self::FAILED_LOGINS_OPTION);
        if (!is_array($counts)) {
            return;
        }
        $currentHour = intdiv($now, HOUR_IN_SECONDS);
        foreach ($counts as $hour => $count) {
            if ((int) $hour < $currentHour) {
                $this->record('login.failed_summary', '', null, null, 'success', [
                    'hour_start' => (int) $hour * HOUR_IN_SECONDS,
                    'count' => (int) $count,
                ], 'cron');
                unset($counts[$hour]);
            }
        }
        update_option(self::FAILED_LOGINS_OPTION, $counts, false);
    }

    // ------------------------------------------------------------------ updates en installaties

    /**
     * @param mixed $response
     * @param array<string, mixed> $hookExtra
     * @return mixed
     */
    public function onPreInstall($response, $hookExtra)
    {
        if (is_array($hookExtra)) {
            if (!empty($hookExtra['plugin']) && is_string($hookExtra['plugin'])) {
                $this->previousVersions['plugin:' . $hookExtra['plugin']] = self::pluginVersion($hookExtra['plugin']);
            }
            if (!empty($hookExtra['theme']) && is_string($hookExtra['theme'])) {
                $this->previousVersions['theme:' . $hookExtra['theme']] = self::themeVersion($hookExtra['theme']);
            }
        }

        return $response;
    }

    /**
     * @param object $upgrader WP_Upgrader
     * @param array<string, mixed> $hookExtra
     */
    public function onUpgraderComplete($upgrader, $hookExtra): void
    {
        if (!is_array($hookExtra)) {
            return;
        }
        $type = (string) ($hookExtra['type'] ?? '');
        $action = (string) ($hookExtra['action'] ?? '');
        $error = self::upgraderError($upgrader);

        if ($type === 'plugin') {
            $files = (array) ($hookExtra['plugins'] ?? (isset($hookExtra['plugin']) ? [$hookExtra['plugin']] : []));
            if ($action === 'install' && method_exists($upgrader, 'plugin_info')) {
                $files = array_filter([$upgrader->plugin_info()]);
            }
            foreach ($files as $file) {
                $this->recordComponentChange('plugin', (string) $file, $action, self::pluginVersion((string) $file), $error);
            }
        } elseif ($type === 'theme') {
            $themes = (array) ($hookExtra['themes'] ?? (isset($hookExtra['theme']) ? [$hookExtra['theme']] : []));
            if ($action === 'install' && method_exists($upgrader, 'theme_info')) {
                $info = $upgrader->theme_info();
                $themes = $info ? [$info->get_stylesheet()] : [];
            }
            foreach ($themes as $stylesheet) {
                $this->recordComponentChange('theme', (string) $stylesheet, $action, self::themeVersion((string) $stylesheet), $error);
            }
        } elseif ($type === 'translation') {
            foreach ((array) ($hookExtra['translations'] ?? []) as $pack) {
                $pack = (array) $pack;
                $this->record('translation.updated', ($pack['type'] ?? '') . ':' . ($pack['slug'] ?? '') . ':' . ($pack['language'] ?? ''), null, isset($pack['version']) ? (string) $pack['version'] : null, $error === null ? 'success' : 'failed');
            }
        }
        // type "core" wordt afgehandeld door _core_updated_successfully
    }

    public function onCoreUpdated(string $newVersion): void
    {
        global $wp_version;
        $this->record('core.updated', 'wordpress', (string) $wp_version, $newVersion);
        $this->touchedKeys[] = 'core:wordpress';
    }

    public function onPreAutoUpdate(): void
    {
        $this->autoUpdate = true;
    }

    /**
     * Geslaagde auto-updates lopen al via upgrader_process_complete; hier alleen de mislukte.
     *
     * @param array<string, mixed> $results
     */
    public function onAutoUpdatesComplete($results): void
    {
        foreach ((array) $results as $type => $items) {
            foreach ((array) $items as $item) {
                if (!is_object($item) || (!is_wp_error($item->result ?? null) && ($item->result ?? null) !== false)) {
                    continue;
                }
                $target = $item->item ?? null;
                $object = (string) ($target->plugin ?? $target->theme ?? $target->slug ?? $item->name ?? $type);
                $message = is_wp_error($item->result ?? null) ? $item->result->get_error_message() : 'mislukt';
                $this->record($type . '.auto_update_failed', $object, isset($target->current) ? (string) $target->current : null, isset($target->new_version) ? (string) $target->new_version : null, 'failed', ['message' => substr($message, 0, 500)], 'auto-update');
            }
        }
    }

    // ------------------------------------------------------------------ plugins en thema's

    public function onPluginActivated(string $plugin): void
    {
        $this->record('plugin.activated', $plugin, null, self::pluginVersion($plugin));
    }

    public function onPluginDeactivated(string $plugin): void
    {
        $this->record('plugin.deactivated', $plugin, null, self::pluginVersion($plugin));
    }

    /**
     * @param mixed $deleted
     */
    public function onPluginDeleted(string $plugin, $deleted): void
    {
        $this->record('plugin.deleted', $plugin, null, null, $deleted ? 'success' : 'failed');
        $this->touchedKeys[] = 'plugin:' . $plugin;
    }

    /**
     * @param mixed $newName
     * @param mixed $newTheme WP_Theme
     * @param mixed $oldTheme WP_Theme
     */
    public function onThemeSwitched($newName, $newTheme, $oldTheme): void
    {
        $new = is_object($newTheme) ? (string) $newTheme->get_stylesheet() : (string) $newName;
        $old = is_object($oldTheme) ? (string) $oldTheme->get_stylesheet() : null;
        $this->record('theme.switched', $new, $old, $new);
    }

    /**
     * @param mixed $deleted
     */
    public function onThemeDeleted(string $stylesheet, $deleted): void
    {
        $this->record('theme.deleted', $stylesheet, null, null, $deleted ? 'success' : 'failed');
        $this->touchedKeys[] = 'theme:' . $stylesheet;
    }

    // ------------------------------------------------------------------ gebruikers

    public function onUserRegistered(int $userId): void
    {
        $user = get_userdata($userId);
        if ($user) {
            $this->record('user.created', (string) $user->user_login, null, implode(',', (array) $user->roles));
        }
    }

    /**
     * @param mixed $oldRoles
     */
    public function onUserRoleSet(int $userId, string $role, $oldRoles): void
    {
        $user = get_userdata($userId);
        $this->record('user.role_changed', $user ? (string) $user->user_login : (string) $userId, implode(',', (array) $oldRoles), $role);
    }

    /**
     * @param mixed $reassign
     * @param mixed $user WP_User (WP 5.5+)
     */
    public function onUserDeleted(int $userId, $reassign = null, $user = null): void
    {
        if (!is_object($user)) {
            $user = get_userdata($userId);
        }
        $this->record('user.deleted', $user ? (string) $user->user_login : (string) $userId, null, null, 'success', [
            'reassigned_to' => $reassign !== null ? (int) $reassign : null,
        ]);
    }

    /**
     * Alleen of het e-mailadres of wachtwoord wijzigde, nooit de waarden zelf.
     *
     * @param mixed $oldUserData WP_User
     */
    public function onProfileUpdated(int $userId, $oldUserData): void
    {
        $user = get_userdata($userId);
        if (!$user || !is_object($oldUserData)) {
            return;
        }
        if ($oldUserData->user_email !== $user->user_email) {
            $this->record('user.email_changed', (string) $user->user_login);
        }
        if ($oldUserData->user_pass !== $user->user_pass) {
            $this->record('user.password_changed', (string) $user->user_login);
        }
    }

    /**
     * Basisniveau: alleen logins van beheerders. Alle logins horen bij de optionele module (§2.8).
     *
     * @param mixed $user WP_User
     */
    public function onLogin(string $login, $user): void
    {
        if (is_object($user) && user_can($user, 'manage_options')) {
            $this->record('user.login', $login, null, null, 'success', [], 'wp-login');
        }
    }

    public function onLoginFailed(): void
    {
        $counts = get_option(self::FAILED_LOGINS_OPTION);
        $counts = is_array($counts) ? $counts : [];
        $hour = (string) intdiv(time(), HOUR_IN_SECONDS);
        $counts[$hour] = ($counts[$hour] ?? 0) + 1;
        update_option(self::FAILED_LOGINS_OPTION, $counts, false);
    }

    // ------------------------------------------------------------------ instellingen

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public function onCriticalOptionUpdated($old, $new, string $option): void
    {
        $this->record('option.changed', $option, self::scalar($old), self::scalar($new));
    }

    // ------------------------------------------------------------------ hulpfuncties

    private function source(): string
    {
        if ($this->jobId !== null) {
            return self::SOURCE_PANEL;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return 'wp-cli';
        }
        if ($this->autoUpdate) {
            return 'auto-update';
        }
        if (wp_doing_cron()) {
            return 'cron';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        if (wp_doing_ajax() || is_admin()) {
            return 'wp-admin';
        }

        return self::SOURCE_UNKNOWN;
    }

    private function recordComponentChange(string $kind, string $id, string $action, ?string $newVersion, ?string $error): void
    {
        $key = $kind . ':' . $id;
        $previous = $this->previousVersions[$key] ?? null;
        $failed = $error !== null || $newVersion === null || ($action === 'update' && $previous !== null && $previous === $newVersion);

        $this->record($kind . '.' . ($action !== '' ? $action : 'changed'), $id, $previous, $newVersion, $failed ? 'failed' : 'success', $error !== null ? ['message' => $error] : []);
        $this->touchedKeys[] = $key;
    }

    /**
     * @param mixed $upgrader
     */
    private static function upgraderError($upgrader): ?string
    {
        $result = is_object($upgrader) && isset($upgrader->skin) && is_object($upgrader->skin) ? ($upgrader->skin->result ?? null) : null;

        return is_wp_error($result) ? substr($result->get_error_message(), 0, 500) : null;
    }

    private static function pluginVersion(string $file): ?string
    {
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (validate_file($file) !== 0 || !is_file($path)) {
            return null;
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $version = (string) (get_plugin_data($path, false, false)['Version'] ?? '');

        return $version !== '' ? $version : null;
    }

    private static function themeVersion(string $stylesheet): ?string
    {
        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists()) {
            return null;
        }
        $version = (string) $theme->get('Version');

        return $version !== '' ? $version : null;
    }

    /**
     * @param mixed $value
     */
    private static function scalar($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return substr(is_scalar($value) ? (string) $value : (string) wp_json_encode($value), 0, 191);
    }
}
