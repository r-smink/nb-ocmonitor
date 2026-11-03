<?php

/**
 * Bouwt en ondertekent een release van de client-plugin.
 *
 *   php scripts/release-plugin.php [pad-naar-release-sleutel]
 *
 * Standaard sleutel: ~/.nb-ocm/release-signing.key (of NB_OCM_RELEASE_KEY_FILE). Die sleutel hoort
 * NIET op de server: alleen wie hem heeft kan updates maken die de plugin accepteert.
 * Resultaat in client-plugin/dist/: de zip en latest.json (versie, sha256, handtekening). Commit
 * die twee en deploy; het panel biedt de update daarna aan bij sites met 0.4.0 of nieuwer.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/client-plugin/nb-ocmonitor-client';
$dist = $root . '/client-plugin/dist';
$home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
$keyFile = $argv[1] ?? (getenv('NB_OCM_RELEASE_KEY_FILE') ?: $home . '/.nb-ocm/release-signing.key');

if (!is_file($keyFile)) {
    fwrite(STDERR, "Release-sleutel niet gevonden: {$keyFile}\n");
    exit(1);
}
$b64d = static fn (string $s): string => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
$b64e = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$secret = $b64d(trim((string) file_get_contents($keyFile)));
if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Ongeldige release-sleutel\n");
    exit(1);
}

$header = (string) file_get_contents($source . '/nb-ocmonitor-client.php', false, null, 0, 4096);
if (preg_match('/^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)/m', $header, $m) !== 1) {
    fwrite(STDERR, "Versie niet gevonden in de plugin-header\n");
    exit(1);
}
$version = $m[1];
$embedded = (string) file_get_contents($source . '/src/Release.php');
$public = $b64e(sodium_crypto_sign_publickey_from_secretkey($secret));
if (strpos($embedded, $public) === false) {
    fwrite(STDERR, "Deze sleutel hoort niet bij Release::PUBLIC_KEY in de plugin\n");
    exit(1);
}

@mkdir($dist, 0775, true);
$zipPath = "{$dist}/nb-ocmonitor-client-{$version}.zip";
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Kan {$zipPath} niet maken\n");
    exit(1);
}
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $f): bool => !in_array($f->getFilename(), ['tests', '.git', '.DS_Store'], true) && !$f->isLink()
));
foreach ($iterator as $file) {
    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
}
sort($files);
foreach ($files as $relative) {
    $name = 'nb-ocmonitor-client/' . $relative;
    $zip->addFile($source . '/' . $relative, $name);
    $zip->setMtimeName($name, 1700000000);
}
$zip->close();

$bytes = (string) file_get_contents($zipPath);
$manifest = [
    'version' => $version,
    'file' => basename($zipPath),
    'sha256' => hash('sha256', $bytes),
    'signature' => $b64e(sodium_crypto_sign_detached($bytes, $secret)),
    'built_at' => gmdate('c'),
];
file_put_contents($dist . '/latest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
foreach (glob($dist . '/nb-ocmonitor-client-*.zip') ?: [] as $old) {
    if ($old !== $zipPath) {
        unlink($old);
    }
}
echo "Release {$version}: {$zipPath}\nsha256 {$manifest['sha256']}\n";
