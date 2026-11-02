<?php

/**
 * Kopieert protocol/src naar de plugin (lib/protocol). WordPress-plugins hebben geen Composer
 * tijdens runtime, dus de plugin levert de library zelf mee.
 *
 *   php scripts/sync-protocol.php          kopiëren
 *   php scripts/sync-protocol.php --check  alleen controleren (CI), exit 1 bij verschil
 */

declare(strict_types=1);

$source = dirname(__DIR__) . '/protocol/src';
$target = dirname(__DIR__) . '/client-plugin/nb-ocmonitor-client/lib/protocol';
$check = in_array('--check', $argv, true);

$sourceFiles = array_map('basename', glob($source . '/*.php') ?: []);
$targetFiles = is_dir($target) ? array_map('basename', glob($target . '/*.php') ?: []) : [];

$differences = [];
foreach ($sourceFiles as $file) {
    if (!in_array($file, $targetFiles, true) || hash_file('sha256', "$source/$file") !== hash_file('sha256', "$target/$file")) {
        $differences[] = $file;
    }
}
$stale = array_diff($targetFiles, $sourceFiles);

if ($check) {
    foreach ($differences as $file) {
        fwrite(STDERR, "verschilt: $file\n");
    }
    foreach ($stale as $file) {
        fwrite(STDERR, "verouderd in plugin: $file\n");
    }
    $ok = $differences === [] && $stale === [];
    echo $ok ? "protocol-kopie in plugin is actueel\n" : "protocol-kopie in plugin wijkt af; draai scripts/sync-protocol.php\n";
    exit($ok ? 0 : 1);
}

if (!is_dir($target) && !mkdir($target, 0755, true)) {
    fwrite(STDERR, "kan $target niet aanmaken\n");
    exit(1);
}
foreach ($differences as $file) {
    copy("$source/$file", "$target/$file");
}
foreach ($stale as $file) {
    unlink("$target/$file");
}
printf("%d bestand(en) gekopieerd, %d verwijderd\n", count($differences), count($stale));
