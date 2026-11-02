<?php

declare(strict_types=1);

if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "ext-sodium ontbreekt. Start met: php -d extension=sodium ...\n");
    exit(2);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'NextBuzz\\OcMonitor\\Protocol\\';
    if (strpos($class, $prefix) === 0) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

/** Vaste invoer voor de testvectoren; moet gelijk blijven aan docs/protocol.md §8. */
const VECTOR_PANEL_SEED = "\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01";
const VECTOR_SITE_SEED = "\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02";
const VECTOR_PAIRING_SECRET = "\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03";
const VECTOR_TS = 1767225600; // 2026-01-01T00:00:00Z
const VECTOR_HOST = 'klant.example.nl';
const VECTOR_SITE_ID = 'site_01';
const VECTOR_NONCE = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYX';
const VECTOR_LOGIN_NONCE = 'GBkaGxwdHh8gISIjJCUmJygpKiss';
const VECTOR_COMMAND_BODY = '{"command":"site.info","args":{},"job_id":"job_1","expires_at":1767225660}';
const VECTOR_EVENT_BODY = '{"events":[{"seq":1,"type":"plugin.updated","slug":"akismet","from":"5.3","to":"5.4"}]}';
const VECTOR_RESPONSE_BODY = '{"ok":true,"wp_version":"6.9"}';
