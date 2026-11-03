<?php

namespace App\Ocm;

/**
 * De ondertekende release van de client-plugin (client-plugin/dist, gemaakt met
 * scripts/release-plugin.php). Het panel kan de handtekening niet maken, alleen doorgeven.
 */
final class PluginRelease
{
    /** Vanaf deze versie kan de plugin zichzelf bijwerken. */
    public const SELF_UPDATE_SINCE = '0.4.0';

    /** @return array{version: string, file: string, sha256: string, signature: string, path: string}|null */
    public static function latest(): ?array
    {
        $dir = rtrim((string) config('ocm.client_plugin_dist'), '/\\');
        $manifest = json_decode((string) @file_get_contents($dir . '/latest.json'), true);
        if (!is_array($manifest) || !isset($manifest['version'], $manifest['file'], $manifest['sha256'], $manifest['signature'])
            || preg_match('/^\d+\.\d+\.\d+$/', (string) $manifest['version']) !== 1
            || $manifest['file'] !== 'nb-ocmonitor-client-' . $manifest['version'] . '.zip') {
            return null;
        }
        $path = $dir . '/' . $manifest['file'];
        if (!is_file($path)) {
            return null;
        }

        return ['version' => $manifest['version'], 'file' => $manifest['file'], 'sha256' => $manifest['sha256'], 'signature' => $manifest['signature'], 'path' => $path];
    }

    /** Kan deze site naar de nieuwste release, via het panel? */
    public static function offerFor(?string $siteVersion): ?array
    {
        $latest = self::latest();
        if ($latest === null || $siteVersion === null || version_compare($siteVersion, self::SELF_UPDATE_SINCE, '<')
            || version_compare($siteVersion, $latest['version'], '>=')) {
            return null;
        }

        return $latest;
    }
}
