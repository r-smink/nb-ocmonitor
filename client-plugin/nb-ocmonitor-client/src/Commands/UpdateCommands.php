<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Updates via de WordPress-upgraders (plan §4.2), scope "updates".
 *
 * Het panel kiest alleen WAT er bijgewerkt wordt; het pakket komt altijd uit de update-transient
 * van de site zelf (wordpress.org of de updater van een premium-plugin). Het panel kan dus geen
 * eigen code laten installeren.
 *
 * Per plugin/thema: kopie van de oude map naar wp-content/nb-ocm-rollback/, update, health check
 * van de homepage via een loopback. Werkte de site vóór de update wel en daarna niet meer, dan
 * wordt de oude versie automatisch teruggezet.
 */
final class UpdateCommands
{
    private const ROLLBACK_DIR = 'nb-ocm-rollback';

    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('update.plugin', 'updates', [$this, 'plugins']);
        $registry->add('update.theme', 'updates', [$this, 'themes']);
        $registry->add('update.core', 'updates', [$this, 'core']);
        $registry->add('update.translations', 'updates', [$this, 'translations']);
    }

    /**
     * @param array<string, mixed> $args {plugins: string[]}
     * @return array<string, mixed>
     */
    public function plugins(array $args, CommandContext $context): array
    {
        $files = UpdateArgs::plugins($args);
        $this->prepare();
        wp_update_plugins();

        $installed = get_plugins();
        $available = self::transientList('update_plugins', 'response');
        $baseline = $this->healthCheck();
        $items = [];

        foreach ($files as $file) {
            if (!isset($installed[$file])) {
                $items[] = self::item($file, null, null, 'not_found', 'Plugin is niet geïnstalleerd');
                continue;
            }
            $from = (string) $installed[$file]['Version'];
            if (!isset($available[$file])) {
                $items[] = self::item($file, $from, $from, 'up_to_date', 'WordPress biedt voor deze plugin geen update aan');
                continue;
            }
            $dir = dirname($file) !== '.' ? WP_PLUGIN_DIR . '/' . dirname($file) : null;
            $items[] = $this->updateOne('plugin', $file, $from, $dir, $baseline, static function () use ($file): array {
                $skin = new \WP_Ajax_Upgrader_Skin();
                $result = (new \Plugin_Upgrader($skin))->bulk_upgrade([$file]);

                return [$skin, is_array($result) ? ($result[$file] ?? null) : $result];
            }, static function () use ($file): ?string {
                wp_clean_plugins_cache(false);
                $plugins = get_plugins();

                return isset($plugins[$file]) ? (string) $plugins[$file]['Version'] : null;
            });
        }

        return ['items' => $items, 'health_before' => $baseline];
    }

    /**
     * @param array<string, mixed> $args {themes: string[]}
     * @return array<string, mixed>
     */
    public function themes(array $args, CommandContext $context): array
    {
        $stylesheets = UpdateArgs::themes($args);
        $this->prepare();
        wp_update_themes();

        $available = self::transientList('update_themes', 'response');
        $baseline = $this->healthCheck();
        $items = [];

        foreach ($stylesheets as $stylesheet) {
            $theme = wp_get_theme($stylesheet);
            if (!$theme->exists()) {
                $items[] = self::item($stylesheet, null, null, 'not_found', 'Thema is niet geïnstalleerd');
                continue;
            }
            $from = (string) $theme->get('Version');
            if (!isset($available[$stylesheet])) {
                $items[] = self::item($stylesheet, $from, $from, 'up_to_date', 'WordPress biedt voor dit thema geen update aan');
                continue;
            }
            $dir = $theme->get_stylesheet_directory();
            $items[] = $this->updateOne('theme', $stylesheet, $from, $dir, $baseline, static function () use ($stylesheet): array {
                $skin = new \WP_Ajax_Upgrader_Skin();
                $result = (new \Theme_Upgrader($skin))->bulk_upgrade([$stylesheet]);

                return [$skin, is_array($result) ? ($result[$stylesheet] ?? null) : $result];
            }, static function () use ($stylesheet): ?string {
                wp_clean_themes_cache(false);
                $theme = wp_get_theme($stylesheet);

                return $theme->exists() ? (string) $theme->get('Version') : null;
            });
        }

        return ['items' => $items, 'health_before' => $baseline];
    }

    /**
     * WordPress zelf. Geen automatische rollback (dat kan WordPress niet veilig); een mislukte
     * health check wordt wel duidelijk gemeld.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function core(array $args, CommandContext $context): array
    {
        global $wp_version;
        UpdateArgs::none($args);
        $this->prepare();
        wp_version_check();

        $from = (string) $wp_version;
        $offer = null;
        foreach ((array) get_core_updates() as $update) {
            if (is_object($update) && ($update->response ?? '') === 'upgrade') {
                $offer = $update;
                break;
            }
        }
        if ($offer === null) {
            return ['items' => [self::item('wordpress', $from, $from, 'up_to_date', 'WordPress biedt geen update aan')]];
        }

        $baseline = $this->healthCheck();
        $skin = new \WP_Ajax_Upgrader_Skin();
        $result = (new \Core_Upgrader($skin))->upgrade($offer, [
            'allow_relaxed_file_ownership' => isset($offer->new_files) && !$offer->new_files,
        ]);
        $error = self::upgradeError($skin, $result);
        if ($error !== null) {
            return ['items' => [self::item('wordpress', $from, $from, 'failed', $error)], 'health_before' => $baseline];
        }

        $after = $this->healthCheck();
        $item = self::item('wordpress', $from, is_string($result) ? $result : null, 'updated', null, $after);
        if ($baseline['ok'] === true && $after['ok'] === false) {
            $item['status'] = 'health_failed';
            $item['message'] = 'De site geeft na de update een fout (' . $after['detail'] . '). Controleer de site direct.';
        }

        return ['items' => [$item], 'health_before' => $baseline];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function translations(array $args, CommandContext $context): array
    {
        UpdateArgs::none($args);
        $this->prepare();

        $updates = wp_get_translation_updates();
        if ($updates === []) {
            return ['items' => [], 'updated' => 0];
        }
        $skin = new \WP_Ajax_Upgrader_Skin();
        $result = (new \Language_Pack_Upgrader($skin))->bulk_upgrade($updates);
        $updated = is_array($result) ? count(array_filter($result, static function ($r): bool {
            return $r !== false && !is_wp_error($r);
        })) : 0;

        return ['items' => [], 'updated' => $updated, 'total' => count($updates), 'error' => self::upgradeError($skin, $result)];
    }

    // ------------------------------------------------------------------ pipeline

    /**
     * @param array{ok: ?bool, status: ?int, detail: string} $baseline
     * @param callable(): array{0: \WP_Ajax_Upgrader_Skin, 1: mixed} $run
     * @param callable(): ?string $version
     * @return array<string, mixed>
     */
    private function updateOne(string $kind, string $id, string $from, ?string $dir, array $baseline, callable $run, callable $version): array
    {
        $backup = $dir !== null ? $this->backup($kind, $dir) : null;

        try {
            [$skin, $result] = $run();
            if ($result === true) {
                return self::item($id, $from, $from, 'up_to_date', 'WordPress biedt geen update aan');
            }
            $error = self::upgradeError($skin, $result);
            $to = $version();

            if ($error !== null || $to === null) {
                // WordPress zet de oude versie bij een mislukte installatie meestal zelf terug; zo niet, dan doen wij het.
                if ($to === null && $backup !== null && $dir !== null && $this->restore($backup, $dir, $kind)) {
                    return self::item($id, $from, $from, 'failed', ($error ?? 'Map verdwenen na update') . ' (oude versie teruggezet)');
                }

                return self::item($id, $from, $to, 'failed', $error ?? 'Na de update is de ' . $kind . ' niet meer gevonden');
            }
            if ($to === $from) {
                return self::item($id, $from, $to, 'failed', 'Versie is na de update niet veranderd');
            }

            $after = $this->healthCheck();
            if ($baseline['ok'] === true && $after['ok'] === false) {
                if ($backup !== null && $dir !== null && $this->restore($backup, $dir, $kind)) {
                    $this->recorder->record($kind . '.rolled_back', $id, $to, $from, 'success', ['reason' => $after['detail']]);
                    return self::item($id, $from, $from, 'rolled_back', 'Site gaf na de update een fout (' . $after['detail'] . '); oude versie teruggezet', $this->healthCheck());
                }

                return self::item($id, $from, $to, 'health_failed', 'Site geeft na de update een fout (' . $after['detail'] . ') en terugzetten was niet mogelijk', $after);
            }

            return self::item($id, $from, $to, 'updated', null, $after);
        } finally {
            if ($backup !== null) {
                $this->deleteDir($backup);
            }
        }
    }

    private function prepare(): void
    {
        if (!wp_is_file_mod_allowed('nb_ocm_updates')) {
            throw new CommandError('file_mods_disabled', 'Bestandswijzigingen zijn uitgeschakeld op deze site (DISALLOW_FILE_MODS)', 409);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Een update mag niet halverwege stoppen als het panel de verbinding verbreekt.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        if (!WP_Filesystem()) {
            throw new CommandError('filesystem', 'WordPress kan hier niet zonder FTP-gegevens schrijven (FS_METHOD of bestandsrechten)', 409);
        }
    }

    /**
     * Haalt de homepage op via een loopback. ok = null als de loopback zelf niet lukt (bijv. door
     * de hosting geblokkeerd); dan beslissen we niet op basis van de health check.
     *
     * @return array{ok: ?bool, status: ?int, detail: string}
     */
    private function healthCheck(): array
    {
        $response = wp_remote_get(add_query_arg('nb_ocm_health', (string) wp_rand(), home_url('/')), [
            'timeout' => 20,
            'redirection' => 3,
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
            'headers' => ['Cache-Control' => 'no-cache'],
            'user-agent' => 'NB-OC-Monitor-Health/' . NB_OCM_VERSION,
        ]);
        if (is_wp_error($response)) {
            return ['ok' => null, 'status' => null, 'detail' => substr($response->get_error_message(), 0, 200)];
        }
        $status = (int) wp_remote_retrieve_response_code($response);

        return ['ok' => $status > 0 && $status < 500, 'status' => $status, 'detail' => 'HTTP ' . $status];
    }

    /** Kopie van de huidige map; null als dat niet kan (dan geen automatische rollback). */
    private function backup(string $kind, string $dir): ?string
    {
        global $wp_filesystem;
        if (!is_dir($dir) || get_filesystem_method() !== 'direct') {
            return null;
        }
        $root = WP_CONTENT_DIR . '/' . self::ROLLBACK_DIR;
        if (!$wp_filesystem->is_dir($root)) {
            if (!$wp_filesystem->mkdir($root, FS_CHMOD_DIR)) {
                return null;
            }
        }
        // Nooit via het web bereikbaar.
        $wp_filesystem->put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n", FS_CHMOD_FILE);
        $wp_filesystem->put_contents($root . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE);

        $target = $root . '/' . $kind . '-' . basename($dir);
        $this->deleteDir($target);
        if (!$wp_filesystem->mkdir($target, FS_CHMOD_DIR) || is_wp_error(copy_dir($dir, $target))) {
            $this->deleteDir($target);
            return null;
        }

        return $target;
    }

    private function restore(string $backup, string $dir, string $kind): bool
    {
        global $wp_filesystem;
        if ($wp_filesystem->exists($dir) && !$wp_filesystem->delete($dir, true)) {
            return false;
        }
        if (!$wp_filesystem->mkdir($dir, FS_CHMOD_DIR) || is_wp_error(copy_dir($backup, $dir))) {
            return false;
        }
        if (function_exists('wp_opcache_invalidate_directory')) {
            wp_opcache_invalidate_directory($dir);
        }
        if ($kind === 'plugin') {
            wp_clean_plugins_cache(false);
        } else {
            wp_clean_themes_cache(false);
        }

        return true;
    }

    private function deleteDir(string $path): void
    {
        global $wp_filesystem;
        if ($wp_filesystem->exists($path)) {
            $wp_filesystem->delete($path, true);
        }
    }

    // ------------------------------------------------------------------ hulpfuncties

    /**
     * @param array{ok: ?bool, status: ?int, detail: string}|null $health
     * @return array<string, mixed>
     */
    private static function item(string $id, ?string $from, ?string $to, string $status, ?string $message, ?array $health = null): array
    {
        return ['id' => $id, 'from' => $from, 'to' => $to, 'status' => $status, 'message' => $message, 'health' => $health];
    }

    /**
     * @param mixed $skin WP_Ajax_Upgrader_Skin
     * @param mixed $result
     */
    private static function upgradeError($skin, $result): ?string
    {
        if (is_object($skin) && is_wp_error($skin->result ?? null)) {
            return substr($skin->result->get_error_message(), 0, 500);
        }
        if (is_object($skin) && method_exists($skin, 'get_errors') && $skin->get_errors()->has_errors()) {
            return substr((string) $skin->get_error_messages(), 0, 500);
        }
        if (is_wp_error($result)) {
            return substr($result->get_error_message(), 0, 500);
        }
        if ($result === false || $result === null) {
            return 'Bijwerken mislukt (bestandssysteem niet beschikbaar)';
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private static function transientList(string $transient, string $field): array
    {
        $value = get_site_transient($transient);

        return is_object($value) && isset($value->{$field}) ? (array) $value->{$field} : [];
    }
}
