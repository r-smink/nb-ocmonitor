<?php

/**
 * Testrunner zonder dependencies (draait ook waar geen Composer/PHPUnit is).
 * Gebruik: php -d extension=sodium tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\Headers;
use NextBuzz\OcMonitor\Protocol\InMemoryNonceStore;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\KeyResolver;
use NextBuzz\OcMonitor\Protocol\LoginToken;
use NextBuzz\OcMonitor\Protocol\Pairing;
use NextBuzz\OcMonitor\Protocol\Signer;
use NextBuzz\OcMonitor\Protocol\StaticKeyResolver;
use NextBuzz\OcMonitor\Protocol\VerificationFailed;
use NextBuzz\OcMonitor\Protocol\Verifier;

$passed = 0;
$failures = [];

function test(string $name, callable $fn): void
{
    global $passed, $failures;
    try {
        $fn();
        $passed++;
    } catch (\Throwable $e) {
        $failures[] = $name . ': ' . get_class($e) . ' ' . $e->getMessage();
    }
}

function check(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function rejects(string $expectedReason, callable $fn): void
{
    try {
        $fn();
    } catch (VerificationFailed $e) {
        check($e->reason() === $expectedReason, "verwacht {$expectedReason}, kreeg {$e->reason()} ({$e->getMessage()})");
        return;
    }
    throw new \RuntimeException("verwacht afwijzing {$expectedReason}, maar verificatie slaagde");
}

$vectors = json_decode((string) file_get_contents(__DIR__ . '/vectors.json'), true, 32, JSON_THROW_ON_ERROR);
$panel = KeyPair::fromSeed(VECTOR_PANEL_SEED);
$site = KeyPair::fromSeed(VECTOR_SITE_SEED);
$cmdPath = '/nb-ocm/v1/cmd';

/** Verifier zoals de plugin hem gebruikt: kent de panel-sleutel. */
function siteVerifier(KeyPair $panel, int $window = Verifier::DEFAULT_WINDOW): Verifier
{
    return new Verifier(new StaticKeyResolver([$panel->publicKey()]), new InMemoryNonceStore(), $window);
}

function signCommand(KeyPair $keys, string $body, int $ts, ?string $nonce = null, string $host = VECTOR_HOST, string $path = '/nb-ocm/v1/cmd', string $siteId = VECTOR_SITE_ID, string $context = Canonical::CTX_COMMAND): array
{
    return (new Signer($keys))->signRequest($context, 'POST', $host, $path, $siteId, $body, $ts, $nonce);
}

// ---------------------------------------------------------------- testvectoren

test('vector: sleutels', function () use ($vectors, $panel, $site) {
    check($vectors['keys']['panel']['public_key'] === $panel->publicKeyB64());
    check($vectors['keys']['panel']['key_id'] === $panel->keyId());
    check($vectors['keys']['site']['key_id'] === $site->keyId());
});

test('vector: command-verzoek reproduceert en verifieert', function () use ($vectors, $panel) {
    $v = $vectors['command_request'];
    $headers = signCommand($panel, $v['body'], $v['timestamp'], $v['nonce']);
    check($headers === $v['headers'], 'headers wijken af van vector');
    check(Canonical::request($v['context'], $v['method'], $v['host'], $v['path'], $v['site_id'], $panel->keyId(), $v['timestamp'], $v['nonce'], $v['body']) === $v['canonical']);
    siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', $v['host'], $v['path'], $v['site_id'], $v['headers'], $v['body'], $v['timestamp']);
});

test('vector: site-event-verzoek verifieert', function () use ($vectors, $site) {
    $v = $vectors['site_event_request'];
    $verifier = new Verifier(new StaticKeyResolver([$site->publicKey()]), new InMemoryNonceStore());
    $verifier->verifyRequest(Canonical::CTX_SITE, 'POST', $v['host'], $v['path'], $v['site_id'], $v['headers'], $v['body'], $v['timestamp']);
});

test('vector: response verifieert', function () use ($vectors, $site) {
    $v = $vectors['response'];
    $headers = (new Signer($site))->signResponse($v['site_id'], $v['request_nonce'], $v['status'], $v['body'], $v['timestamp']);
    check($headers === $v['headers']);
    $verifier = new Verifier(new StaticKeyResolver([$site->publicKey()]), new InMemoryNonceStore());
    check($verifier->verifyResponse($v['site_id'], $v['request_nonce'], 200, $v['headers'], $v['body'], $v['timestamp']) === $site->keyId());
});

test('vector: login-token reproduceert en verifieert', function () use ($vectors, $panel) {
    $v = $vectors['login_token'];
    check(LoginToken::issue($panel, $v['site_id'], $v['host'], $v['wp_user'], $v['panel_user'], $v['now'], 60, $v['nonce']) === $v['token']);
    $claims = siteVerifier($panel)->verifyLoginToken($v['token'], $v['site_id'], $v['host'], $v['now'] + 5);
    check($claims['wp_user'] === 'nb-rick');
});

test('vector: pairing-bewijs verifieert', function () use ($vectors, $site) {
    $proof = $vectors['pairing_proof']['proof'];
    check(Pairing::proof(VECTOR_SITE_ID, $site, 'https://' . VECTOR_HOST, VECTOR_PAIRING_SECRET, VECTOR_TS) === $proof);
    check(Pairing::verifyProof($proof, VECTOR_SITE_ID, VECTOR_PAIRING_SECRET, VECTOR_TS + 10) === $site->publicKey());
});

// ---------------------------------------------------------------- verzoeken: negatieve tests

$body = VECTOR_COMMAND_BODY;
$ts = VECTOR_TS;

test('verzoek: geldige roundtrip met willekeurige sleutel', function () {
    $keys = KeyPair::generate();
    $headers = signCommand($keys, '{}', 1000);
    siteVerifier($keys)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, '/nb-ocm/v1/cmd', VECTOR_SITE_ID, $headers, '{}', 1000);
});

test('verzoek: WP-stijl headernamen (underscores) worden geaccepteerd', function () use ($panel, $body, $ts, $cmdPath) {
    $wpHeaders = [];
    foreach (signCommand($panel, $body, $ts) as $name => $value) {
        $wpHeaders[str_replace('-', '_', $name)] = [$value];
    }
    siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $wpHeaders, $body, $ts);
});

test('verzoek: timestamp te oud', function () use ($panel, $body, $ts, $cmdPath) {
    rejects(VerificationFailed::STALE_TIMESTAMP, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, signCommand($panel, $body, $ts), $body, $ts + 91));
});

test('verzoek: timestamp in de toekomst', function () use ($panel, $body, $ts, $cmdPath) {
    rejects(VerificationFailed::STALE_TIMESTAMP, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, signCommand($panel, $body, $ts), $body, $ts - 91));
});

test('verzoek: replay wordt geweigerd', function () use ($panel, $body, $ts, $cmdPath) {
    $verifier = siteVerifier($panel);
    $headers = signCommand($panel, $body, $ts);
    $verifier->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts);
    rejects(VerificationFailed::REPLAY, fn () => $verifier->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts + 1));
});

test('verzoek: ongeldige handtekening verbruikt de nonce niet', function () use ($panel, $body, $ts, $cmdPath) {
    $verifier = siteVerifier($panel);
    $headers = signCommand($panel, $body, $ts);
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => $verifier->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body . ' ', $ts));
    $verifier->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts);
});

test('verzoek: gewijzigde body', function () use ($panel, $body, $ts, $cmdPath) {
    $tampered = str_replace('site.info', 'user.create', $body);
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, signCommand($panel, $body, $ts), $tampered, $ts));
});

test('verzoek: ondertekend voor andere host', function () use ($panel, $body, $ts, $cmdPath) {
    $headers = signCommand($panel, $body, $ts, null, 'andere-klant.nl');
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: host-normalisatie (hoofdletters en :443)', function () use ($panel, $body, $ts, $cmdPath) {
    $headers = signCommand($panel, $body, $ts, null, 'Klant.Example.NL:443');
    siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts);
});

test('verzoek: ondertekend voor ander endpoint', function () use ($panel, $body, $ts, $cmdPath) {
    $headers = signCommand($panel, $body, $ts, null, VECTOR_HOST, '/nb-ocm/v1/other');
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: site-id header van andere site', function () use ($panel, $body, $ts, $cmdPath) {
    $headers = signCommand($panel, $body, $ts, null, VECTOR_HOST, $cmdPath, 'site_02');
    rejects(VerificationFailed::SITE_MISMATCH, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: site-id header vervalst, handtekening voor andere site', function () use ($panel, $body, $ts, $cmdPath) {
    $headers = signCommand($panel, $body, $ts, null, VECTOR_HOST, $cmdPath, 'site_02');
    $headers[Headers::SITE] = VECTOR_SITE_ID;
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: site-event-handtekening is geen command (domain separation)', function () use ($panel, $body, $ts, $cmdPath) {
    $headers = signCommand($panel, $body, $ts, null, VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, Canonical::CTX_SITE);
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: GET-handtekening niet bruikbaar als POST', function () use ($panel, $body, $ts, $cmdPath) {
    $headers = (new Signer($panel))->signRequest(Canonical::CTX_COMMAND, 'GET', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $body, $ts);
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: onbekende sleutel', function () use ($body, $ts, $cmdPath, $panel) {
    $attacker = KeyPair::generate();
    rejects(VerificationFailed::UNKNOWN_KEY, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, signCommand($attacker, $body, $ts), $body, $ts));
});

test('verzoek: eigen sleutel met key-id van panel', function () use ($body, $ts, $cmdPath, $panel) {
    $attacker = KeyPair::generate();
    $headers = signCommand($attacker, $body, $ts);
    $headers[Headers::KEY] = $panel->keyId();
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: resolver die verkeerde sleutel bij key-id geeft', function () use ($body, $ts, $cmdPath, $panel) {
    $attacker = KeyPair::generate();
    $resolver = new class ($attacker) implements KeyResolver {
        private KeyPair $k;
        public function __construct(KeyPair $k) { $this->k = $k; }
        public function publicKey(string $keyId): ?string { return $this->k->publicKey(); }
    };
    $headers = signCommand($attacker, $body, $ts);
    $headers[Headers::KEY] = $panel->keyId();
    $verifier = new Verifier($resolver, new InMemoryNonceStore());
    rejects(VerificationFailed::UNKNOWN_KEY, fn () => $verifier->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
});

test('verzoek: ontbrekende of misvormde headers', function () use ($panel, $body, $ts, $cmdPath) {
    $good = signCommand($panel, $body, $ts);
    $cases = [
        [Headers::SIG, null, VerificationFailed::MALFORMED],
        [Headers::SIG, $good[Headers::SIG] . 'A', VerificationFailed::MALFORMED],
        [Headers::SIG, $good[Headers::SIG] . '==', VerificationFailed::MALFORMED],
        [Headers::TS, '0123', VerificationFailed::MALFORMED],
        [Headers::TS, (string) $ts . ' ', VerificationFailed::MALFORMED],
        [Headers::NONCE, "abc\nPOST", VerificationFailed::MALFORMED],
        [Headers::NONCE, 'kort', VerificationFailed::MALFORMED],
        [Headers::KEY, "k_x\n", VerificationFailed::MALFORMED],
        [Headers::SITE, null, VerificationFailed::SITE_MISMATCH],
    ];
    foreach ($cases as [$name, $value, $reason]) {
        $headers = $good;
        if ($value === null) {
            unset($headers[$name]);
        } else {
            $headers[$name] = $value;
        }
        rejects($reason, fn () => siteVerifier($panel)->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, $headers, $body, $ts));
    }
});

test('verzoek: sleutelrotatie, oude en nieuwe sleutel beide geldig', function () use ($panel, $body, $ts, $cmdPath) {
    $new = KeyPair::generate();
    $verifier = new Verifier(new StaticKeyResolver([$panel->publicKey(), $new->publicKey()]), new InMemoryNonceStore());
    $verifier->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, signCommand($panel, $body, $ts), $body, $ts);
    $verifier->verifyRequest(Canonical::CTX_COMMAND, 'POST', VECTOR_HOST, $cmdPath, VECTOR_SITE_ID, signCommand($new, $body, $ts), $body, $ts);
});

// ---------------------------------------------------------------- responses

test('response: gebonden aan request-nonce', function () use ($site) {
    $verifier = new Verifier(new StaticKeyResolver([$site->publicKey()]), new InMemoryNonceStore());
    $headers = (new Signer($site))->signResponse(VECTOR_SITE_ID, VECTOR_NONCE, 200, '{}', 1000);
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => $verifier->verifyResponse(VECTOR_SITE_ID, Signer::newNonce(), 200, $headers, '{}', 1000));
});

test('response: andere status of body', function () use ($site) {
    $verifier = new Verifier(new StaticKeyResolver([$site->publicKey()]), new InMemoryNonceStore());
    $headers = (new Signer($site))->signResponse(VECTOR_SITE_ID, VECTOR_NONCE, 500, '{"ok":false}', 1000);
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => $verifier->verifyResponse(VECTOR_SITE_ID, VECTOR_NONCE, 200, $headers, '{"ok":false}', 1000));
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => $verifier->verifyResponse(VECTOR_SITE_ID, VECTOR_NONCE, 500, $headers, '{"ok":true}', 1000));
});

test('response: panel-sleutel is geen site-sleutel', function () use ($site, $panel) {
    $verifier = new Verifier(new StaticKeyResolver([$site->publicKey()]), new InMemoryNonceStore());
    $headers = (new Signer($panel))->signResponse(VECTOR_SITE_ID, VECTOR_NONCE, 200, '{}', 1000);
    rejects(VerificationFailed::UNKNOWN_KEY, fn () => $verifier->verifyResponse(VECTOR_SITE_ID, VECTOR_NONCE, 200, $headers, '{}', 1000));
});

// ---------------------------------------------------------------- login-tokens

/** Bouwt een token met willekeurige claims, om afwijzingen na een geldige handtekening te testen. */
function rawLoginToken(KeyPair $keys, array $claims): string
{
    $payload = Base64Url::encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    return $keys->keyId() . '.' . $payload . '.' . Base64Url::encode($keys->sign(Canonical::loginToken($keys->keyId(), $payload)));
}

$baseClaims = ['aud' => 'login', 'site_id' => VECTOR_SITE_ID, 'host' => VECTOR_HOST, 'wp_user' => 'nb-rick', 'panel_user' => 'rick', 'iat' => $ts, 'exp' => $ts + 60, 'nonce' => VECTOR_LOGIN_NONCE];

test('login: eenmalig bruikbaar', function () use ($panel, $ts) {
    $verifier = siteVerifier($panel);
    $token = LoginToken::issue($panel, VECTOR_SITE_ID, VECTOR_HOST, 'nb-rick', 'rick', $ts);
    $verifier->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts);
    rejects(VerificationFailed::REPLAY, fn () => $verifier->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts + 1));
});

test('login: verlopen', function () use ($panel, $ts) {
    $token = LoginToken::issue($panel, VECTOR_SITE_ID, VECTOR_HOST, 'nb-rick', 'rick', $ts);
    rejects(VerificationFailed::EXPIRED, fn () => siteVerifier($panel)->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts + 60 + Verifier::LOGIN_SKEW + 1));
});

test('login: andere site of host', function () use ($panel, $ts) {
    $token = LoginToken::issue($panel, VECTOR_SITE_ID, VECTOR_HOST, 'nb-rick', 'rick', $ts);
    rejects(VerificationFailed::SITE_MISMATCH, fn () => siteVerifier($panel)->verifyLoginToken($token, 'site_02', VECTOR_HOST, $ts));
    rejects(VerificationFailed::SITE_MISMATCH, fn () => siteVerifier($panel)->verifyLoginToken($token, VECTOR_SITE_ID, 'andere-klant.nl', $ts));
});

test('login: verkeerde audience', function () use ($panel, $baseClaims, $ts) {
    $token = rawLoginToken($panel, ['aud' => 'command'] + $baseClaims);
    rejects(VerificationFailed::WRONG_AUDIENCE, fn () => siteVerifier($panel)->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts));
});

test('login: te lange levensduur', function () use ($panel, $baseClaims, $ts) {
    $token = rawLoginToken($panel, ['exp' => $ts + 3600] + $baseClaims);
    rejects(VerificationFailed::MALFORMED, fn () => siteVerifier($panel)->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts));
});

test('login: iat ver in de toekomst', function () use ($panel, $baseClaims, $ts) {
    $token = rawLoginToken($panel, ['iat' => $ts + 600, 'exp' => $ts + 660] + $baseClaims);
    rejects(VerificationFailed::MALFORMED, fn () => siteVerifier($panel)->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts));
});

test('login: claims met verkeerde types', function () use ($panel, $baseClaims, $ts) {
    foreach ([['exp' => (string) ($ts + 60)], ['wp_user' => ['admin']], ['wp_user' => "admin\n"], ['nonce' => 'x']] as $override) {
        $token = rawLoginToken($panel, $override + $baseClaims);
        rejects(VerificationFailed::MALFORMED, fn () => siteVerifier($panel)->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts));
    }
});

test('login: gewijzigde payload', function () use ($panel, $ts) {
    $token = LoginToken::issue($panel, VECTOR_SITE_ID, VECTOR_HOST, 'nb-rick', 'rick', $ts);
    [$kid, $payload, $sig] = explode('.', $token);
    $claims = json_decode(Base64Url::decode($payload), true);
    $claims['wp_user'] = 'admin';
    $forged = $kid . '.' . Base64Url::encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES)) . '.' . $sig;
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyLoginToken($forged, VECTOR_SITE_ID, VECTOR_HOST, $ts));
});

test('login: command-handtekening is geen login-token', function () use ($panel, $baseClaims, $ts) {
    $payload = Base64Url::encode((string) json_encode($baseClaims));
    $sig = Base64Url::encode($panel->sign(Canonical::CTX_COMMAND . "\n" . $payload));
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => siteVerifier($panel)->verifyLoginToken($panel->keyId() . '.' . $payload . '.' . $sig, VECTOR_SITE_ID, VECTOR_HOST, $ts));
});

test('login: misvormde tokens', function () use ($panel, $ts) {
    foreach (['', 'a.b', 'a.b.c.d', str_repeat('a', 3000) . '.b.c'] as $token) {
        rejects(VerificationFailed::MALFORMED, fn () => siteVerifier($panel)->verifyLoginToken($token, VECTOR_SITE_ID, VECTOR_HOST, $ts));
    }
});

// ---------------------------------------------------------------- pairing

test('pairing: code roundtrip', function () use ($panel, $ts) {
    $code = Pairing::createCode('https://ocm.nextbuzz.nl/', VECTOR_SITE_ID, $panel, VECTOR_PAIRING_SECRET, $ts + 900);
    $parsed = Pairing::parseCode("  {$code}\n", $ts);
    check($parsed['panel_pk'] === $panel->publicKey());
    check($parsed['secret'] === VECTOR_PAIRING_SECRET);
    check($parsed['panel_url'] === 'https://ocm.nextbuzz.nl');
});

test('pairing: verlopen code', function () use ($panel, $ts) {
    $code = Pairing::createCode('https://ocm.nextbuzz.nl', VECTOR_SITE_ID, $panel, VECTOR_PAIRING_SECRET, $ts);
    rejects(VerificationFailed::EXPIRED, fn () => Pairing::parseCode($code, $ts + 1));
});

test('pairing: panel-URL zonder https', function () use ($panel, $ts) {
    $code = Pairing::createCode('http://ocm.nextbuzz.nl', VECTOR_SITE_ID, $panel, VECTOR_PAIRING_SECRET, $ts + 900);
    rejects(VerificationFailed::MALFORMED, fn () => Pairing::parseCode($code, $ts));
});

test('pairing: publieke sleutel in code vervangen', function () use ($panel, $ts) {
    $code = Pairing::createCode('https://ocm.nextbuzz.nl', VECTOR_SITE_ID, $panel, VECTOR_PAIRING_SECRET, $ts + 900);
    $data = json_decode(Base64Url::decode(substr($code, strlen(Pairing::CODE_PREFIX))), true);
    $data['panel_pk'] = KeyPair::generate()->publicKeyB64();
    $tampered = Pairing::CODE_PREFIX . Base64Url::encode((string) json_encode($data, JSON_UNESCAPED_SLASHES));
    rejects(VerificationFailed::MALFORMED, fn () => Pairing::parseCode($tampered, $ts));
});

test('pairing: bewijs met verkeerd secret', function () use ($site, $ts) {
    $proof = Pairing::proof(VECTOR_SITE_ID, $site, 'https://' . VECTOR_HOST, random_bytes(32), $ts);
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => Pairing::verifyProof($proof, VECTOR_SITE_ID, VECTOR_PAIRING_SECRET, $ts));
});

test('pairing: andere publieke sleutel in bewijs (geen bezit private key)', function () use ($site, $ts) {
    $proof = Pairing::proof(VECTOR_SITE_ID, $site, 'https://' . VECTOR_HOST, VECTOR_PAIRING_SECRET, $ts);
    $proof['site_pk'] = KeyPair::generate()->publicKeyB64();
    $canonical = Canonical::pairing(VECTOR_SITE_ID, $proof['site_pk'], $proof['site_url'], $ts);
    $proof['hmac'] = Base64Url::encode(hash_hmac('sha256', $canonical, VECTOR_PAIRING_SECRET, true));
    rejects(VerificationFailed::BAD_SIGNATURE, fn () => Pairing::verifyProof($proof, VECTOR_SITE_ID, VECTOR_PAIRING_SECRET, $ts));
});

test('pairing: verkeerde site en oud bewijs', function () use ($site, $ts) {
    $proof = Pairing::proof(VECTOR_SITE_ID, $site, 'https://' . VECTOR_HOST, VECTOR_PAIRING_SECRET, $ts);
    rejects(VerificationFailed::SITE_MISMATCH, fn () => Pairing::verifyProof($proof, 'site_02', VECTOR_PAIRING_SECRET, $ts));
    rejects(VerificationFailed::STALE_TIMESTAMP, fn () => Pairing::verifyProof($proof, VECTOR_SITE_ID, VECTOR_PAIRING_SECRET, $ts + Pairing::PROOF_WINDOW + 1));
});

// ---------------------------------------------------------------- base64url

test('base64url: strikt', function () {
    check(Base64Url::decode(Base64Url::encode("\xff\x00\xfe")) === "\xff\x00\xfe");
    foreach (['a', 'ab=', 'a+b/', "YWJj\n", 'YR'] as $bad) {
        rejects(VerificationFailed::MALFORMED, fn () => Base64Url::decode($bad));
    }
});

// ---------------------------------------------------------------- resultaat

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL {$failure}\n");
}
printf("%d geslaagd, %d mislukt\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);
