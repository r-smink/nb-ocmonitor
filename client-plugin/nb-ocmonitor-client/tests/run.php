<?php

/**
 * Tests voor de beveiligingskritische kern van de plugin (Endpoint), zonder WordPress.
 * Het "panel" ondertekent hier met de protocol-library, precies zoals het echte panel.
 *
 * Gebruik: php -d extension=sodium tests/run.php
 */

declare(strict_types=1);

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Commands\UpdateArgs;
use NextBuzz\OcMonitor\Client\Endpoint;
use NextBuzz\OcMonitor\Client\EndpointResult;
use NextBuzz\OcMonitor\Client\FailureLog;
use NextBuzz\OcMonitor\Client\Scopes;
use NextBuzz\OcMonitor\Client\Security\ClientIp;
use NextBuzz\OcMonitor\Client\Security\LoginSecurity;
use NextBuzz\OcMonitor\Client\Security\TrustedDevice;
use NextBuzz\OcMonitor\Client\Commands\UserArgs;
use NextBuzz\OcMonitor\Client\Commands\LicenseArgs;
use NextBuzz\OcMonitor\Client\Release;
use NextBuzz\OcMonitor\Client\Backup\BackupArgs;
use NextBuzz\OcMonitor\Client\Backup\BackupCrypto;
use NextBuzz\OcMonitor\Client\Backup\DatabaseDump;
use NextBuzz\OcMonitor\Client\SiteConfig;
use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\Headers;
use NextBuzz\OcMonitor\Protocol\InMemoryNonceStore;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\Signer;
use NextBuzz\OcMonitor\Protocol\StaticKeyResolver;
use NextBuzz\OcMonitor\Protocol\Verifier;

if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "ext-sodium ontbreekt. Start met: php -d extension=sodium ...\n");
    exit(2);
}

spl_autoload_register(static function (string $class): void {
    $map = [
        'NextBuzz\\OcMonitor\\Protocol\\' => __DIR__ . '/../lib/protocol/',
        'NextBuzz\\OcMonitor\\Client\\' => __DIR__ . '/../src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            require $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            return;
        }
    }
});

final class MemoryFailureLog implements FailureLog
{
    /** @var string[] */
    public array $reasons = [];

    public function record(string $reason, string $ip, int $now): void
    {
        $this->reasons[] = $reason;
    }
}

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

const HOST = 'klant.example.nl';
const SITE_ID = 'site_01';
const NOW = 1767225600;

final class Fixture
{
    public KeyPair $panel;
    public KeyPair $site;
    public InMemoryNonceStore $nonces;
    public MemoryFailureLog $failures;
    public CommandRegistry $commands;
    /** @var list<?string> */
    public array $jobScopeCalls = [];
    /** @var string[] */
    public array $scopes = ['read', 'events'];

    public function __construct()
    {
        $this->panel = KeyPair::fromSeed(str_repeat("\x01", 32));
        $this->site = KeyPair::fromSeed(str_repeat("\x02", 32));
        $this->nonces = new InMemoryNonceStore();
        $this->failures = new MemoryFailureLog();
        $this->commands = new CommandRegistry();
        $this->commands->add('site.info', 'read', static function (array $args, CommandContext $ctx): array {
            return ['site_id' => $ctx->config->siteId, 'echo' => $args];
        });
        $this->commands->add('user.create', 'users', static function (): array {
            return ['created' => true];
        });
        $this->commands->add('test.fail', 'read', static function (): array {
            throw new \LogicException('kapot');
        });
        $this->commands->add('test.command_error', 'read', static function (): array {
            throw new CommandError('not_found', 'Plugin bestaat niet', 404);
        });
    }

    public function endpoint(bool $paired = true): Endpoint
    {
        $config = $paired ? new SiteConfig(SITE_ID, HOST, 'https://ocm.nextbuzz.nl', [$this->panel->publicKey()], $this->site, $this->scopes) : null;

        return new Endpoint($config, $this->nonces, $this->commands, $this->failures, function (?string $jobId): void {
            $this->jobScopeCalls[] = $jobId;
        });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function body(string $command, array $overrides = []): string
    {
        return (string) json_encode(array_merge(['command' => $command, 'args' => new \stdClass(), 'job_id' => 'job_1', 'expires_at' => NOW + 60], $overrides));
    }

    /**
     * @return array<string, string>
     */
    public function sign(string $body, ?KeyPair $keys = null, string $host = HOST, int $ts = NOW): array
    {
        return (new Signer($keys ?? $this->panel))->signRequest(Canonical::CTX_COMMAND, 'POST', $host, Endpoint::ROUTE, SITE_ID, $body, $ts);
    }

    /**
     * @param array<string, string> $requestHeaders
     * @return array<string, mixed>
     */
    public function verifiedPayload(EndpointResult $result, array $requestHeaders): array
    {
        check(!$result->notFound, 'verwacht een ondertekend antwoord, kreeg 404');
        $verifier = new Verifier(new StaticKeyResolver([$this->site->publicKey()]), new InMemoryNonceStore());
        $verifier->verifyResponse(SITE_ID, $requestHeaders[Headers::NONCE], $result->status, $result->headers, $result->body, NOW);

        return json_decode($result->body, true, 16, JSON_THROW_ON_ERROR);
    }
}

function handle(Fixture $f, string $body, array $headers, bool $paired = true): EndpointResult
{
    return $f->endpoint($paired)->handle('POST', $headers, $body, '203.0.113.7', NOW);
}

// ---------------------------------------------------------------- geldige verzoeken

test('geldig command: ondertekend antwoord met resultaat', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info', ['args' => ['x' => 1]]);
    $headers = $f->sign($body);
    $payload = $f->verifiedPayload(handle($f, $body, $headers), $headers);
    check($payload['ok'] === true && $payload['job_id'] === 'job_1');
    check($payload['result'] === ['site_id' => SITE_ID, 'echo' => ['x' => 1]]);
});

test('job-scope wordt gezet en daarna gewist (ook bij een exception)', function () {
    $f = new Fixture();
    foreach (['site.info', 'test.fail'] as $command) {
        $body = Fixture::body($command);
        handle($f, $body, $f->sign($body));
    }
    check($f->jobScopeCalls === ['job_1', null, 'job_1', null], 'jobscope: ' . json_encode($f->jobScopeCalls));
});

test('WordPress-headers (underscores, arrays) werken', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    $signed = $f->sign($body);
    $wp = [];
    foreach ($signed as $name => $value) {
        $wp[str_replace('-', '_', $name)] = [$value];
    }
    $f->verifiedPayload(handle($f, $body, $wp), $signed);
});

// ---------------------------------------------------------------- alles vóór de handtekening: 404

test('niet gekoppeld: 404 zonder logging', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    $result = handle($f, $body, $f->sign($body), false);
    check($result->notFound && $result->body === '' && $result->headers === []);
    check($f->failures->reasons === []);
});

test('ongeldige handtekening: 404, reden gelogd, geen uitvoering', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    $result = handle($f, Fixture::body('user.create'), $f->sign($body));
    check($result->notFound);
    check($f->failures->reasons === ['bad_signature']);
    check($f->jobScopeCalls === []);
});

test('sleutel van aanvaller: 404', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    check(handle($f, $body, $f->sign($body, KeyPair::generate()))->notFound);
    check($f->failures->reasons === ['unknown_key']);
});

test('replay: tweede keer 404', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    $headers = $f->sign($body);
    check(!handle($f, $body, $headers)->notFound);
    check(handle($f, $body, $headers)->notFound);
    check($f->failures->reasons === ['replay']);
});

test('ondertekend voor andere site-host: 404', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    check(handle($f, $body, $f->sign($body, null, 'andere-klant.nl'))->notFound);
});

test('verlopen timestamp: 404', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    check(handle($f, $body, $f->sign($body, null, HOST, NOW - 600))->notFound);
    check($f->failures->reasons === ['stale_timestamp']);
});

test('te grote body: 404 vóór crypto', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info', ['args' => ['pad' => str_repeat('a', Endpoint::MAX_BODY_BYTES)]]);
    check(handle($f, $body, $f->sign($body))->notFound);
    check($f->failures->reasons === ['malformed']);
});

test('ontbrekende headers: 404', function () {
    $f = new Fixture();
    check(handle($f, Fixture::body('site.info'), [])->notFound);
});

// ---------------------------------------------------------------- na de handtekening: ondertekende fouten

/**
 * @return array<string, mixed>
 */
function signedError(Fixture $f, string $body, int $expectedStatus): array
{
    $headers = $f->sign($body);
    $result = handle($f, $body, $headers);
    check($result->status === $expectedStatus, "status {$result->status}, verwacht {$expectedStatus}: {$result->body}");
    $payload = $f->verifiedPayload($result, $headers);
    check($payload['ok'] === false);

    return $payload['error'];
}

test('onbekend command (niet in allowlist)', function () {
    $f = new Fixture();
    check(signedError($f, Fixture::body('system.exec'), 400)['code'] === 'unknown_command');
});

test('command buiten scope', function () {
    $f = new Fixture();
    check(signedError($f, Fixture::body('user.create'), 403)['code'] === 'scope_denied');
    check($f->jobScopeCalls === [], 'mag niet uitgevoerd zijn');
});

test('command binnen extra scope', function () {
    $f = new Fixture();
    $f->scopes[] = 'users';
    $body = Fixture::body('user.create');
    $headers = $f->sign($body);
    check($f->verifiedPayload(handle($f, $body, $headers), $headers)['result'] === ['created' => true]);
});

test('verlopen job en te lange geldigheid', function () {
    $f = new Fixture();
    check(signedError($f, Fixture::body('site.info', ['expires_at' => NOW - 1]), 400)['code'] === 'expired');
    check(signedError($f, Fixture::body('site.info', ['expires_at' => NOW + Endpoint::MAX_JOB_LIFETIME + 1]), 400)['code'] === 'expired');
});

test('schemafouten in de body', function () {
    $f = new Fixture();
    $cases = [
        '[]',
        'geen json',
        Fixture::body('site.info', ['extra' => 1]),
        Fixture::body('site.info', ['args' => [1, 2]]),
        Fixture::body('site.info', ['job_id' => 'job 1']),
        Fixture::body('site.info', ['expires_at' => (string) (NOW + 60)]),
        Fixture::body('Site.Info'),
        Fixture::body('site'),
    ];
    foreach ($cases as $body) {
        check(signedError($f, $body, 400)['code'] === 'bad_request', 'body: ' . $body);
    }
});

test('exception in handler: ondertekende 500', function () {
    $f = new Fixture();
    $error = signedError($f, Fixture::body('test.fail'), 500);
    check($error['code'] === 'internal' && strpos($error['message'], 'kapot') !== false);
});

test('CommandError in handler behoudt status en code', function () {
    $f = new Fixture();
    check(signedError($f, Fixture::body('test.command_error'), 404)['code'] === 'not_found');
});

test('antwoord kan niet voor een ander verzoek gebruikt worden', function () {
    $f = new Fixture();
    $body = Fixture::body('site.info');
    $result = handle($f, $body, $f->sign($body));
    $other = $f->sign(Fixture::body('site.info', ['job_id' => 'job_2']));
    $threw = false;
    try {
        $f->verifiedPayload($result, $other);
    } catch (\NextBuzz\OcMonitor\Protocol\VerificationFailed $e) {
        $threw = true;
    }
    check($threw, 'antwoord had niet mogen verifiëren tegen een ander verzoek');
});

// ---------------------------------------------------------------- scopes en update-argumenten

test('scopes: updates en users staan altijd aan, ook bij oude configuraties', function () {
    check(Scopes::normalize([]) === ['read', 'events', 'updates', 'users', 'maintenance', 'licenses']);
    check(Scopes::normalize(['read', 'events']) === ['read', 'events', 'updates', 'users', 'maintenance', 'licenses']);
    check(Scopes::normalize(['admin', 1]) === ['read', 'events', 'updates', 'users', 'maintenance', 'licenses']);
});

test('update-command zonder scope updates wordt geweigerd', function () {
    $f = new Fixture();
    $f->commands->add('update.plugin', 'updates', static function (): array {
        return ['items' => []];
    });
    check(signedError($f, Fixture::body('update.plugin', ['args' => ['plugins' => ['akismet/akismet.php']]]), 403)['code'] === 'scope_denied');
    $f->scopes[] = 'updates';
    $body = Fixture::body('update.plugin', ['args' => ['plugins' => ['akismet/akismet.php']], 'job_id' => 'job_2']);
    $headers = $f->sign($body);
    check($f->verifiedPayload(handle($f, $body, $headers), $headers)['result'] === ['items' => []]);
});

test('update-argumenten: geldige lijsten', function () {
    check(UpdateArgs::plugins(['plugins' => ['akismet/akismet.php', 'hello.php', 'akismet/akismet.php']]) === ['akismet/akismet.php', 'hello.php']);
    check(UpdateArgs::themes(['themes' => ['twentytwentyfive', 'astra-child']]) === ['twentytwentyfive', 'astra-child']);
    UpdateArgs::none([]);
});

test('update-argumenten: padmanipulatie, verkeerde types en te veel items worden geweigerd', function () {
    $bad = [
        ['plugins' => ['../wp-config.php']],
        ['plugins' => ['akismet/../../x.php']],
        ['plugins' => ['/etc/passwd.php']],
        ['plugins' => ['a/b/c.php']],
        ['plugins' => ['akismet/akismet']],
        ['plugins' => 'akismet/akismet.php'],
        ['plugins' => []],
        ['plugins' => ['x' => 'akismet/akismet.php']],
        ['plugins' => [123]],
        ['plugins' => ['akismet/akismet.php'], 'package' => 'https://evil.example/x.zip'],
        ['plugins' => array_fill(0, UpdateArgs::MAX_ITEMS + 1, 'a.php')],
    ];
    foreach ($bad as $i => $args) {
        $threw = false;
        try {
            UpdateArgs::plugins($args);
        } catch (CommandError $e) {
            $threw = $e->errorCode() === 'bad_request';
        }
        check($threw, 'geval ' . $i . ' had geweigerd moeten worden');
    }
    foreach ([['themes' => ['../x']], ['themes' => ['a/b']], ['themes' => ['..']]] as $args) {
        $threw = false;
        try {
            UpdateArgs::themes($args);
        } catch (CommandError $e) {
            $threw = true;
        }
        check($threw, 'thema-args hadden geweigerd moeten worden: ' . json_encode($args));
    }
    $threw = false;
    try {
        UpdateArgs::none(['version' => '1.0']);
    } catch (CommandError $e) {
        $threw = true;
    }
    check($threw, 'update.core accepteert geen argumenten');
});

// ---------------------------------------------------------------- inlogbeveiliging

test('client-ip: proxy-headers alleen geloofd van Cloudflare of een private proxy', function () {
    check(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']) === '203.0.113.7');
    check(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']) === '203.0.113.7');
    check(ClientIp::resolve(['REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.9']) === '198.51.100.9');
    check(ClientIp::resolve(['REMOTE_ADDR' => '2606:4700::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::5']) === '2001:db8::5');
    check(ClientIp::resolve(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9, 10.0.0.2']) === '198.51.100.9');
    check(ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'onzin']) === '10.0.0.5');
    check(ClientIp::resolve([]) === '0.0.0.0');
});

test('client-ip: ranges, validatie en inkorten', function () {
    check(ClientIp::inRange('198.51.100.77', '198.51.100.0/24'));
    check(!ClientIp::inRange('198.51.101.1', '198.51.100.0/24'));
    check(ClientIp::inRange('10.1.2.3', '10.0.0.0/9'));
    check(!ClientIp::inRange('10.200.0.1', '10.0.0.0/9'));
    check(ClientIp::inRange('2001:db8::1', '2001:db8::/32'));
    check(!ClientIp::inRange('2001:db8::1', '198.51.100.0/24'));
    check(ClientIp::inRange('203.0.113.5', '203.0.113.5'));
    check(ClientIp::isValidRange('2001:db8::/48') && !ClientIp::isValidRange('1.2.3.4/33') && !ClientIp::isValidRange('kantoor'));
    check(ClientIp::anonymize('198.51.100.77') === '198.51.100.0/24');
    check(ClientIp::anonymize('2001:db8:1234:5678::1') === '2001:db8:1234::/48');
});

test('inlogbeveiliging: instellingen worden begrensd en ongeldige waarden vallen terug', function () {
    check(LoginSecurity::normalize(null) === LoginSecurity::DEFAULTS);
    $c = LoginSecurity::normalize(['lockout' => '0', 'max_attempts' => 1, 'lockout_minutes' => 99999, 'xmlrpc' => 'hack', 'allowlist' => "198.51.100.1\nkantoor, 10.0.0.0/8 198.51.100.1"]);
    check($c['lockout'] === false && $c['max_attempts'] === 3 && $c['lockout_minutes'] === 1440 && $c['xmlrpc'] === 'auto');
    check($c['allowlist'] === ['198.51.100.1', '10.0.0.0/8'], json_encode($c['allowlist']));
});

// ---------------------------------------------------------------- release, gebruikers en 2FA

test('release: alleen een zip met een geldige release-handtekening wordt geaccepteerd', function () {
    $release = KeyPair::generate();
    $zip = 'PK' . random_bytes(200);
    $sig = Base64Url::encode(sodium_crypto_sign_detached($zip, Base64Url::decode($release->secretKeyB64())));
    $pub = Base64Url::encode($release->publicKey());
    check(Release::verify($zip, $sig, $pub));
    check(!Release::verify($zip . 'x', $sig, $pub), 'gewijzigde zip');
    check(!Release::verify($zip, $sig), 'andere sleutel (de echte release-sleutel)');
    check(!Release::verify($zip, 'onzin', $pub));
    check(strlen(Base64Url::decode(Release::PUBLIC_KEY)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
});

test('self-update-argumenten: geen URL, alleen versie, sha256 en handtekening', function () {
    $ok = ['version' => '0.4.1', 'sha256' => str_repeat('a', 64), 'signature' => str_repeat('A', 86)];
    check(UpdateArgs::selfUpdate($ok) === $ok);
    foreach ([$ok + ['url' => 'https://evil.example/x.zip'], ['version' => '../1'] + $ok, ['sha256' => 'abc'] + $ok, ['signature' => 'x'] + $ok] as $i => $bad) {
        $threw = false;
        try {
            UpdateArgs::selfUpdate($bad);
        } catch (CommandError $e) {
            $threw = true;
        }
        check($threw, 'geval ' . $i);
    }
});

test('gebruikers-argumenten: nooit naar administrator, alleen geldige ids en modes', function () {
    check(UserArgs::userId(['user_id' => 7]) === 7);
    check(UserArgs::demote(['user_id' => 7, 'role' => 'editor']) === ['user_id' => 7, 'role' => 'editor']);
    check(UserArgs::twoFactorMode(['mode' => 'admins']) === 'admins');
    foreach ([
        static function () { UserArgs::userId(['user_id' => '7']); },
        static function () { UserArgs::userId(['user_id' => 0]); },
        static function () { UserArgs::userId(['user_id' => 7, 'role' => 'x']); },
        static function () { UserArgs::demote(['user_id' => 7, 'role' => 'administrator']); },
        static function () { UserArgs::twoFactorMode(['mode' => 'all']); },
    ] as $i => $call) {
        $threw = false;
        try {
            $call();
        } catch (CommandError $e) {
            $threw = true;
        }
        check($threw, 'geval ' . $i);
    }
});

test('vertrouwd apparaat: verloopt, en vervalt na een nieuw wachtwoord', function () {
    $hash = '$P$Babcdefghijklmnopqrstuvwxyz012';
    $cookie = TrustedDevice::make(5, $hash, 2000, 'geheim');
    check(TrustedDevice::valid($cookie, 5, $hash, 1000, 'geheim'));
    check(!TrustedDevice::valid($cookie, 5, $hash, 2001, 'geheim'), 'verlopen');
    check(!TrustedDevice::valid($cookie, 6, $hash, 1000, 'geheim'), 'andere gebruiker');
    check(!TrustedDevice::valid($cookie, 5, '$P$Bzzzzzzzzzzzzzzzzzzzzzzzzzz', 1000, 'geheim'), 'nieuw wachtwoord');
    check(!TrustedDevice::valid('9999.' . str_repeat('0', 64), 5, $hash, 1000, 'geheim'), 'vervalste mac');
});

// ---------------------------------------------------------------- backups

test('backup-versleuteling: terug te lezen, en gewijzigde of afgekapte bestanden worden geweigerd', function () {
    $dir = sys_get_temp_dir() . '/nbocm-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $plain = random_bytes(2500000); // drie blokken
    file_put_contents("$dir/in", $plain);
    $key = BackupCrypto::generateKey();
    BackupCrypto::encryptFile("$dir/in", "$dir/enc", $key);
    check(strpos((string) file_get_contents("$dir/enc"), substr($plain, 1000, 64)) === false, 'versleuteld');
    BackupCrypto::decryptFile("$dir/enc", "$dir/out", $key);
    check(file_get_contents("$dir/out") === $plain, 'gelijk na ontsleutelen');

    $cases = [
        'andere sleutel' => static function () use ($dir) { copy("$dir/enc", "$dir/x"); return BackupCrypto::generateKey(); },
        'gewijzigd' => static function () use ($dir, $key) { $b = file_get_contents("$dir/enc"); $b[2000] = chr(ord($b[2000]) ^ 1); file_put_contents("$dir/x", $b); return $key; },
        'afgekapt' => static function () use ($dir, $key) { file_put_contents("$dir/x", substr((string) file_get_contents("$dir/enc"), 0, 1048700)); return $key; },
        'aangevuld' => static function () use ($dir, $key) { file_put_contents("$dir/x", file_get_contents("$dir/enc") . 'x'); return $key; },
    ];
    foreach ($cases as $name => $prepare) {
        $k = $prepare();
        $threw = false;
        try {
            BackupCrypto::decryptFile("$dir/x", "$dir/out2", $k);
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        check($threw && !file_exists("$dir/out2"), $name);
    }
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
});

test('backup-argumenten: alleen https, geen sleutel van het panel, geen padmanipulatie', function () {
    $links = ['db' => ['url' => 'https://ams3.digitaloceanspaces.com/b/db?X-Amz=1', 'headers' => ['Host' => 'x']], 'files' => ['url' => 'https://ams3.digitaloceanspaces.com/b/f', 'headers' => []]];
    $ok = BackupArgs::create(['backup_id' => 'bk_' . str_repeat('a', 20), 'plugins' => ['akismet/akismet.php'], 'core' => true, 'uploads' => $links]);
    check($ok['plugins'] === ['akismet/akismet.php'] && $ok['themes'] === [] && $ok['core'] === true && $ok['full'] === false);
    check(BackupArgs::create(['backup_id' => 'bk_' . str_repeat('a', 20), 'full' => true, 'uploads' => $links])['full'] === true);
    $bad = [
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => $links, 'key' => 'panelsleutel'],
        ['backup_id' => '../x', 'uploads' => $links],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => ['db' => ['url' => 'http://evil.example/x'], 'files' => $links['files']]],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => ['db' => $links['db']]],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => ['db' => ['url' => 'https://x.example/a', 'headers' => ["X\r\nEvil" => 'a']], 'files' => $links['files']]],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'plugins' => ['../../wp-config.php'], 'uploads' => $links],
    ];
    foreach ($bad as $i => $args) {
        $threw = false;
        try {
            BackupArgs::create($args);
        } catch (CommandError $e) {
            $threw = true;
        }
        check($threw, 'create ' . $i);
    }
    $r = BackupArgs::restore(['backup_id' => 'bk_' . str_repeat('b', 20), 'parts' => ['files'], 'downloads' => ['files' => $links['files']], 'sha256' => ['files' => str_repeat('c', 64), 'db' => str_repeat('d', 64)]]);
    check($r['parts'] === ['files'] && array_keys($r['sha256']) === ['files']);
    $threw = false;
    try {
        BackupArgs::restore(['backup_id' => 'bk_' . str_repeat('b', 20), 'parts' => ['files', 'db'], 'downloads' => ['files' => $links['files']], 'sha256' => ['files' => str_repeat('c', 64)]]);
    } catch (CommandError $e) {
        $threw = true;
    }
    check($threw, 'db zonder link of sha256');
});

test('database-import: alleen eigen statements, altijd naar tijdelijke tabellen', function () {
    check(DatabaseDump::toTemporary('INSERT INTO `wp_posts` VALUES (1)') === ['INSERT INTO `nbocmtmp_wp_posts` VALUES (1)', 'wp_posts']);
    check(DatabaseDump::toTemporary('CREATE TABLE `wp_options` (x int)')[0] === 'CREATE TABLE `nbocmtmp_wp_options` (x int)');
    check(DatabaseDump::toTemporary('DROP TABLE IF EXISTS `wp_users`;')[0] === 'DROP TABLE IF EXISTS `nbocmtmp_wp_users`;');
    check(DatabaseDump::toTemporary('DROP DATABASE wordpress')[0] === null);
    check(DatabaseDump::toTemporary('UPDATE `wp_users` SET user_pass = 1')[0] === null);
});

// ---------------------------------------------------------------- licenties

test('licentie-argumenten: vaste methodes, nette sleutel, alleen licentie-opties', function () {
    check(LicenseArgs::activate(['adapter' => 'elementor_pro', 'key' => 'abc123def456']) === ['adapter' => 'elementor_pro', 'key' => 'abc123def456', 'option' => null]);
    check(LicenseArgs::activate(['adapter' => 'option', 'key' => 'XXXX-YYYY', 'option' => 'frm_pro_license_key'])['option'] === 'frm_pro_license_key');
    $bad = [
        ['adapter' => 'eval', 'key' => 'abcd'],
        ['adapter' => 'acf_pro', 'key' => "abc'; DROP"],
        ['adapter' => 'acf_pro', 'key' => 'abcd', 'option' => 'x_license'],
        ['adapter' => 'option', 'key' => 'abcd', 'option' => 'siteurl'],
        ['adapter' => 'option', 'key' => 'abcd', 'option' => 'default_role'],
        ['adapter' => 'option', 'key' => 'abcd', 'option' => 'nb_ocm_license'],
        ['adapter' => 'option', 'key' => 'abcd', 'option' => 'License Key'],
        ['adapter' => 'option', 'key' => 'abcd'],
        ['adapter' => 'acf_pro', 'key' => 'abcd', 'code' => 'phpinfo()'],
    ];
    foreach ($bad as $i => $args) {
        $threw = false;
        try {
            LicenseArgs::activate($args);
        } catch (CommandError $e) {
            $threw = true;
        }
        check($threw, 'geval ' . $i);
    }
});

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL {$failure}\n");
}
printf("%d geslaagd, %d mislukt\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);
