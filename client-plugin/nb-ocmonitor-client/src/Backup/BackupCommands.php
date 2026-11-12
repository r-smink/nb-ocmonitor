<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Backup;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Protocol\Base64Url;

/**
 * Backup vlak vóór updates (scope "updates"): de database en de mappen van de onderdelen die
 * bijgewerkt worden. Versleuteld met een sleutel die alleen op de site staat en via een tijdelijke
 * link rechtstreeks naar de opslag (DigitalOcean Spaces) geüpload. Het panel bewaart de backup
 * 7 dagen en kan hem terugzetten (backup.restore).
 */
final class BackupCommands
{
    public const KEY_OPTION = 'nb_ocm_backup_key';
    private const WORK_DIR = 'nb-ocm-backups';
    /** Niet in een volledige backup: caches, tijdelijke mappen en backups van andere plugins. */
    private const SKIP_CONTENT = ['plugins', 'themes', 'cache', 'upgrade', 'upgrade-temp-backup', 'nb-ocm-backups', 'nb-ocm-rollback',
        'updraft', 'ai1wm-backups', 'backups-dup-lite', 'backups-dup-pro', 'wpvividbackups', 'et-cache', 'litespeed', 'wflogs', 'debug.log'];

    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('backup.create', 'updates', [$this, 'create']);
        $registry->add('backup.restore', 'updates', [$this, 'restore']);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function create(array $args, CommandContext $context): array
    {
        $a = BackupArgs::create($args);
        $work = $this->workDir($a['backup_id']);

        // Op de achtergrond: eerst het ondertekende "geaccepteerd" naar het panel, dan de verbinding
        // sluiten en pas daarna de backup maken. Zo kan een proxy-timeout (nginx 504) hem niet afbreken.
        // Het resultaat gaat als event backup.created naar het panel.
        if ($a['async'] && self::canFinishRequest()) {
            register_shutdown_function(function () use ($a, $work): void {
                self::finishRequest();
                try {
                    $this->run($a, $work);
                } catch (\Throwable $e) {
                    // Al vastgelegd als event backup.created met result failed.
                }
                $this->recorder->flush();
            });

            return ['backup_id' => $a['backup_id'], 'accepted' => true];
        }

        return $this->run($a, $work);
    }

    /** Antwoord afronden terwijl PHP doorwerkt: PHP-FPM en LiteSpeed. Bij mod_php kan dat niet. */
    public static function canFinishRequest(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    private static function finishRequest(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * @param array<string, mixed> $a gevalideerde argumenten (BackupArgs::create)
     * @return array<string, mixed>
     */
    private function run(array $a, string $work): array
    {
        $started = microtime(true);
        try {
            $sql = $work . '/db.sql.gz';
            $db = DatabaseDump::export($sql);
            $zip = $work . '/files.zip';
            $components = $this->zipComponents($zip, $a);

            $key = self::key();
            $result = ['backup_id' => $a['backup_id'], 'db' => $db, 'components' => $components];
            foreach (['db' => $sql, 'files' => $zip] as $part => $file) {
                $encrypted = $file . '.enc';
                BackupCrypto::encryptFile($file, $encrypted, $key);
                @unlink($file);
                self::upload($a['uploads'][$part], $encrypted);
                $result[$part . '_size'] = (int) filesize($encrypted);
                $result[$part . '_sha256'] = hash_file('sha256', $encrypted);
                @unlink($encrypted);
            }
            $result['seconds'] = round(microtime(true) - $started, 1);
            // Volledig resultaat in het event: daarmee rondt het panel een backup op de achtergrond af.
            $this->recorder->record('backup.created', $a['backup_id'], null, null, 'success', [
                'tables' => $db['tables'], 'size' => $result['db_size'] + $result['files_size'],
                'db_size' => $result['db_size'], 'files_size' => $result['files_size'],
                'db_sha256' => $result['db_sha256'], 'files_sha256' => $result['files_sha256'], 'seconds' => $result['seconds'],
            ]);

            return $result;
        } catch (CommandError $e) {
            $this->recorder->record('backup.created', $a['backup_id'], null, null, 'failed', ['message' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        } catch (\Throwable $e) {
            $this->recorder->record('backup.created', $a['backup_id'], null, null, 'failed', ['message' => mb_substr($e->getMessage(), 0, 300)]);
            throw new CommandError('backup_failed', 'Backup mislukt: ' . $e->getMessage(), 500);
        } finally {
            self::removeDir($work);
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function restore(array $args, CommandContext $context): array
    {
        $a = BackupArgs::restore($args);
        $work = $this->workDir($a['backup_id'] . '-restore');
        $key = self::key();
        $result = ['backup_id' => $a['backup_id'], 'parts' => $a['parts']];
        try {
            $plain = [];
            foreach ($a['parts'] as $part) {
                $encrypted = $work . '/' . $part . '.enc';
                self::download($a['downloads'][$part], $encrypted);
                if (!hash_equals($a['sha256'][$part], (string) hash_file('sha256', $encrypted))) {
                    throw new CommandError('checksum_mismatch', 'De backup (' . $part . ') is onderweg veranderd', 422);
                }
                $plain[$part] = $work . '/' . $part . ($part === 'db' ? '.sql.gz' : '.zip');
                BackupCrypto::decryptFile($encrypted, $plain[$part], $key);
                @unlink($encrypted);
            }
            // Eerst de bestanden (zo nodig terug te draaien), dan de database (atomair omgewisseld).
            if (isset($plain['files'])) {
                $result['components'] = $this->restoreFiles($plain['files'], $work . '/files');
            }
            if (isset($plain['db'])) {
                $result['db'] = DatabaseDump::import($plain['db']);
            }
            wp_clean_plugins_cache(false);
            wp_clean_themes_cache(false);
            $this->recorder->record('backup.restored', $a['backup_id'], null, null, 'success', ['parts' => $a['parts']]);

            return $result;
        } catch (CommandError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->recorder->record('backup.restored', $a['backup_id'], null, null, 'failed', ['message' => mb_substr($e->getMessage(), 0, 300)]);
            throw new CommandError('restore_failed', 'Terugzetten mislukt: ' . $e->getMessage(), 500);
        } finally {
            self::removeDir($work);
        }
    }

    /**
     * @param array{plugins: string[], themes: string[], core: bool, full: bool, backup_id: string} $a
     * @return array<string, mixed>
     */
    private function zipComponents(string $path, array $a): array
    {
        if ($a['full']) {
            // Alles: alle plugins, alle thema's, WordPress zelf en de rest van wp-content (uploads, talen, mu-plugins).
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $a['plugins'] = array_keys(get_plugins());
            $a['themes'] = array_keys(wp_get_themes());
            $a['core'] = true;
        }
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP-extensie zip ontbreekt op de server');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Kan zip niet maken');
        }
        $done = ['plugins' => [], 'themes' => [], 'core' => [], 'content' => [], 'full' => $a['full']];
        foreach ($a['plugins'] as $file) {
            $dir = dirname($file);
            if (in_array($dir === '.' ? $file : $dir, $done['plugins'], true)) {
                continue;
            }
            if ($dir === '.') {
                if (is_file(WP_PLUGIN_DIR . '/' . $file)) {
                    $zip->addFile(WP_PLUGIN_DIR . '/' . $file, 'plugins/' . $file);
                    $done['plugins'][] = $file;
                }
            } elseif (is_dir(WP_PLUGIN_DIR . '/' . $dir)) {
                self::addDir($zip, WP_PLUGIN_DIR . '/' . $dir, 'plugins/' . $dir);
                $done['plugins'][] = $dir;
            }
        }
        foreach ($a['themes'] as $stylesheet) {
            $dir = get_theme_root($stylesheet) . '/' . $stylesheet;
            if (is_dir($dir)) {
                self::addDir($zip, $dir, 'themes/' . $stylesheet);
                $done['themes'][] = $stylesheet;
            }
        }
        if ($a['core']) {
            foreach (['wp-admin', 'wp-includes'] as $dir) {
                self::addDir($zip, ABSPATH . $dir, 'core/' . $dir);
                $done['core'][] = $dir;
            }
            foreach (glob(ABSPATH . '*.php') ?: [] as $file) {
                if (basename($file) !== 'wp-config.php') {
                    $zip->addFile($file, 'core/root/' . basename($file));
                }
            }
            $done['core'][] = 'root';
        }
        if ($a['full']) {
            foreach (scandir(WP_CONTENT_DIR) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP_CONTENT, true) || is_link(WP_CONTENT_DIR . '/' . $entry)) {
                    continue;
                }
                if (is_dir(WP_CONTENT_DIR . '/' . $entry)) {
                    self::addDir($zip, WP_CONTENT_DIR . '/' . $entry, 'content/' . $entry);
                } else {
                    $zip->addFile(WP_CONTENT_DIR . '/' . $entry, 'content/' . $entry);
                }
                $done['content'][] = $entry;
            }
        }
        $zip->addFromString('manifest.json', (string) wp_json_encode(['backup_id' => $a['backup_id'], 'created' => time(), 'components' => $done]));
        if (!$zip->close()) {
            throw new \RuntimeException('Kan zip niet afronden');
        }

        return $done;
    }

    /** @return array<string, string[]> */
    private function restoreFiles(string $zipPath, string $extractTo): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Bestandsbackup niet leesbaar');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        // Geen paden buiten de doelmap (zip-slip).
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (strpos($name, '..') !== false || strpos($name, '\\') !== false || $name[0] === '/') {
                $zip->close();
                throw new \RuntimeException('Ongeldig pad in de backup');
            }
        }
        if (!is_array($manifest) || !$zip->extractTo($extractTo)) {
            $zip->close();
            throw new \RuntimeException('Bestandsbackup kon niet uitgepakt worden');
        }
        $zip->close();
        $components = (array) ($manifest['components'] ?? []);
        // Klassen die we na het terugzetten nog nodig hebben vooraf laden: de pluginmap kan zo vervangen worden.
        class_exists(DatabaseDump::class);
        class_exists(BackupCrypto::class);

        $targets = [];
        foreach ((array) ($components['plugins'] ?? []) as $name) {
            $targets[] = [$extractTo . '/plugins/' . $name, WP_PLUGIN_DIR . '/' . $name];
        }
        foreach ((array) ($components['themes'] ?? []) as $name) {
            $targets[] = [$extractTo . '/themes/' . $name, get_theme_root((string) $name) . '/' . $name];
        }
        foreach ($targets as [$from, $to]) {
            if (preg_match('#(^|/)\.\.(/|$)#', $from . $to) === 1) {
                throw new \RuntimeException('Ongeldig pad');
            }
            $old = $to . '.nb-ocm-old';
            self::removeDir($old);
            if (file_exists($to) && !@rename($to, $old)) {
                throw new \RuntimeException('Kan ' . basename($to) . ' niet vervangen');
            }
            if (!@rename($from, $to)) {
                @rename($old, $to);
                throw new \RuntimeException('Kan ' . basename($to) . ' niet terugzetten');
            }
            self::removeDir($old);
        }
        foreach ((array) ($components['content'] ?? []) as $entry) {
            $entry = basename((string) $entry);
            if ($entry === '' || in_array($entry, self::SKIP_CONTENT, true)) {
                continue;
            }
            $from = $extractTo . '/content/' . $entry;
            is_dir($from) ? self::copyDir($from, WP_CONTENT_DIR . '/' . $entry) : (is_file($from) ? copy($from, WP_CONTENT_DIR . '/' . $entry) : null);
        }
        if (in_array('wp-admin', (array) ($components['core'] ?? []), true)) {
            foreach (['wp-admin', 'wp-includes'] as $dir) {
                self::copyDir($extractTo . '/core/' . $dir, ABSPATH . $dir);
            }
            foreach (glob($extractTo . '/core/root/*.php') ?: [] as $file) {
                if (basename($file) !== 'wp-config.php') {
                    copy($file, ABSPATH . basename($file));
                }
            }
        }

        return $components;
    }

    private static function key(): string
    {
        if (defined('NB_OCM_BACKUP_KEY')) {
            $key = Base64Url::decode((string) constant('NB_OCM_BACKUP_KEY'));
        } else {
            $stored = get_option(self::KEY_OPTION);
            if (!is_string($stored) || $stored === '') {
                $stored = Base64Url::encode(BackupCrypto::generateKey());
                update_option(self::KEY_OPTION, $stored, false);
            }
            $key = Base64Url::decode($stored);
        }
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new CommandError('backup_key_invalid', 'Backupsleutel op de site is ongeldig', 500);
        }

        return $key;
    }

    /** @param array{url: string, headers: array<string, string>} $link */
    private static function upload(array $link, string $file): void
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP-extensie curl ontbreekt');
        }
        if (!wp_http_validate_url($link['url'])) {
            throw new \RuntimeException('Uploadadres niet toegestaan');
        }
        $fh = fopen($file, 'rb');
        $ch = curl_init($link['url']);
        $headers = [];
        foreach ($link['headers'] as $name => $value) {
            if (strtolower($name) !== 'host') {
                $headers[] = $name . ': ' . $value;
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => filesize($file),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Upload naar de opslag mislukt (' . ($status ?: $error) . ')' . (is_string($body) && $body !== '' ? ': ' . mb_substr(wp_strip_all_tags($body), 0, 200) : ''));
        }
    }

    /** @param array{url: string, headers: array<string, string>} $link */
    private static function download(array $link, string $file): void
    {
        if (!wp_http_validate_url($link['url'])) {
            throw new \RuntimeException('Downloadadres niet toegestaan');
        }
        $headers = array_filter($link['headers'], static function (string $name): bool {
            return strtolower($name) !== 'host';
        }, ARRAY_FILTER_USE_KEY);
        $response = wp_safe_remote_get($link['url'], ['timeout' => 900, 'stream' => true, 'filename' => $file, 'headers' => $headers, 'redirection' => 0]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            @unlink($file);
            throw new \RuntimeException('Download van de backup mislukt' . (is_wp_error($response) ? ': ' . $response->get_error_message() : ' (HTTP ' . wp_remote_retrieve_response_code($response) . ')'));
        }
    }

    private function workDir(string $name): string
    {
        $root = WP_CONTENT_DIR . '/' . self::WORK_DIR;
        if (!is_dir($root)) {
            wp_mkdir_p($root);
            @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n");
        }
        $dir = $root . '/' . preg_replace('/[^a-z0-9_-]/', '', $name);
        self::removeDir($dir);
        if (!wp_mkdir_p($dir)) {
            throw new CommandError('backup_failed', 'Kan geen werkmap maken in wp-content', 500);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(1200); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        ignore_user_abort(true);

        return $dir;
    }

    private static function addDir(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        $zip->addEmptyDir($prefix);
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }
            $relative = $prefix . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
            $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
        }
    }

    private static function copyDir(string $from, string $to): void
    {
        if (!is_dir($from)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                wp_mkdir_p($target);
            } elseif (!@copy($item->getPathname(), $target)) {
                throw new \RuntimeException('Kan ' . $target . ' niet terugzetten');
            }
        }
    }

    private static function removeDir(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
