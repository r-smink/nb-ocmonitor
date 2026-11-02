<?php

namespace App\Ocm;

use RuntimeException;
use ZipArchive;

/**
 * Bouwt de download-zip van de client-plugin uit de meegeleverde plugin-map.
 */
final class PluginPackage
{
    private const EXCLUDE = ['tests', '.git', '.DS_Store'];

    public static function path(): string
    {
        return rtrim((string) config('ocm.client_plugin_path'), '/\\');
    }

    public static function version(): ?string
    {
        $main = self::path() . '/nb-ocmonitor-client.php';
        if (!is_file($main)) {
            return null;
        }
        $header = (string) file_get_contents($main, false, null, 0, 4096);

        return preg_match('/^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)/m', $header, $m) === 1 ? $m[1] : null;
    }

    /** @return string pad naar een tijdelijke zip; de aanroeper verwijdert hem na het versturen */
    public static function build(): string
    {
        $source = self::path();
        if (!is_dir($source)) {
            throw new RuntimeException('Client-plugin niet gevonden in ' . $source);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'nbocm') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Kan zip niet aanmaken');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), self::EXCLUDE, true) && !$file->isLink()
            )
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $zip->addFile($file->getPathname(), 'nb-ocmonitor-client/' . $relative);
        }
        $zip->close();

        return $zipPath;
    }
}
