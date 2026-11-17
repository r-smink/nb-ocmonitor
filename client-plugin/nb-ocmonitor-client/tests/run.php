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
use NextBuzz\OcMonitor\Client\Commands\ManageArgs;
use NextBuzz\OcMonitor\Client\Commands\IntegrityCheck;
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
    check($ok['async'] === false && BackupArgs::create(['backup_id' => 'bk_' . str_repeat('a', 20), 'async' => true, 'uploads' => $links])['async'] === true);
    $bad = [
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => $links, 'key' => 'panelsleutel'],
        ['backup_id' => '../x', 'uploads' => $links],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => ['db' => ['url' => 'http://evil.example/x'], 'files' => $links['files']]],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => ['db' => $links['db']]],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'uploads' => ['db' => ['url' => 'https://x.example/a', 'headers' => ["X\r\nEvil" => 'a']], 'files' => $links['files']]],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'plugins' => ['../../wp-config.php'], 'uploads' => $links],
        ['backup_id' => 'bk_' . str_repeat('a', 20), 'async' => 'ja', 'uploads' => $links],
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

test('plugin-beheer: alleen een wordpress.org-slug, deze plugin is beschermd', function () {
    check(ManageArgs::install(['slug' => 'wordfence']) === ['slug' => 'wordfence', 'activate' => false]);
    check(ManageArgs::install(['slug' => 'contact-form-7', 'activate' => true])['activate'] === true);
    $bad = [
        ['slug' => 'https://evil.example/x.zip'], ['slug' => '../x'], ['slug' => 'Wordfence'], ['slug' => ''],
        ['slug' => 'nb-ocmonitor-client'], ['slug' => 'akismet', 'url' => 'https://x'], ['slug' => 'akismet', 'activate' => 'ja'],
    ];
    foreach ($bad as $i => $args) {
        $threw = false;
        try {
            ManageArgs::install($args);
        } catch (CommandError $e) {
            $threw = true;
        }
        check($threw, 'install ' . $i);
    }
    check(ManageArgs::plugins(['plugins' => ['akismet/akismet.php']]) === ['akismet/akismet.php']);
    check(ManageArgs::plugins(['plugins' => ['nb-ocmonitor-client/nb-ocmonitor-client.php']], true) === ['nb-ocmonitor-client/nb-ocmonitor-client.php']);
    $threw = false;
    try {
        ManageArgs::plugins(['plugins' => ['nb-ocmonitor-client/nb-ocmonitor-client.php']]);
    } catch (CommandError $e) {
        $threw = $e->errorCode() === 'forbidden';
    }
    check($threw, 'eigen plugin deactiveren/verwijderen moet geweigerd worden');
});

test('onderhoudsmodus: geldige argumenten, hoogstens 7 dagen', function () {
    $on = ManageArgs::maintenance(['enabled' => true, 'message' => ' Even geduld '], NOW);
    check($on['enabled'] === true && $on['message'] === 'Even geduld' && $on['until'] === NOW + ManageArgs::MAX_MAINTENANCE_SECONDS);
    check(ManageArgs::maintenance(['enabled' => true, 'until' => NOW + 3600], NOW)['until'] === NOW + 3600);
    check(ManageArgs::maintenance(['enabled' => true, 'until' => NOW + 30 * 86400], NOW)['until'] === NOW + ManageArgs::MAX_MAINTENANCE_SECONDS);
    check(ManageArgs::maintenance(['enabled' => false], NOW) === ['enabled' => false, 'message' => '', 'until' => null]);
    foreach ([['enabled' => 'true'], ['enabled' => true, 'until' => NOW - 1], ['enabled' => true, 'message' => str_repeat('x', 501)], ['enabled' => true, 'html' => '<b>']] as $i => $args) {
        $threw = false;
        try {
            ManageArgs::maintenance($args, NOW);
        } catch (CommandError $e) {
            $threw = true;
        }
        check($threw, 'maintenance ' . $i);
    }
});

test('integriteit: gewijzigd, ontbrekend en extra PHP worden herkend', function () {
    $checksums = ['wp-includes/a.php' => md5('a'), 'wp-includes/b.php' => md5('b'), 'wp-admin/c.php' => md5('c'), 'wp-content/x.php' => md5('x')];
    $core = IntegrityCheck::coreOnly($checksums);
    check(!isset($core['wp-content/x.php']));
    $files = ['wp-includes/a.php' => 'a', 'wp-includes/b.php' => 'B!', 'wp-includes/evil.php' => '<?php', 'wp-includes/readme.txt' => 'x'];
    $r = IntegrityCheck::compare($core, function (string $p) use ($files): ?string {
        return isset($files[$p]) ? md5($files[$p]) : null;
    }, array_keys($files));
    check($r['modified'] === ['wp-includes/b.php'], 'modified');
    check($r['missing'] === ['wp-admin/c.php'], 'missing');
    check($r['unknown'] === ['wp-includes/evil.php'], 'alleen php telt als onbekend');
    check($r['counts'] === ['modified' => 1, 'missing' => 1, 'unknown' => 1]);

    check(IntegrityCheck::unknownRootPhp(['index.php', 'wp-config.php', 'x1.php', 'robots.txt', 'shell.PHTML'], ['index.php' => md5('i')]) === ['shell.PHTML', 'x1.php']);

    $plugin = IntegrityCheck::pluginChecksums(['files' => ['a.php' => ['md5' => md5('a')], 'b.php' => ['md5' => [md5('b1'), md5('b2')]], '../x.php' => ['md5' => 'x']]]);
    check(count($plugin) === 2, 'paden met .. vallen weg');
    $r = IntegrityCheck::compare($plugin, function (string $p): ?string {
        return $p === 'a.php' ? md5('a') : md5('b2');
    }, ['a.php', 'b.php']);
    check($r['modified'] === [] && $r['missing'] === [], 'een van meerdere md5-waarden is genoeg');
});

// ---------------------------------------------------------------- schakelaars

test('schakelaars: alleen bekende sleutels, waarden begrensd', function () {
    $t = \NextBuzz\OcMonitor\Client\Tweaks::normalize(['disable_comments' => 1, 'revisions' => 999, 'heartbeat' => 'eval', 'onbekend' => true]);
    check($t['disable_comments'] === true && $t['revisions'] === 50 && $t['heartbeat'] === 'default' && !isset($t['onbekend']));
    check(\NextBuzz\OcMonitor\Client\Tweaks::normalize(null)['revisions'] === -1);
});

// ---------------------------------------------------------------- PHP-fouten

test('php-fouten: paden relatief, geen stack trace, getallen tellen niet mee in de sleutel', function () {
    $e = \NextBuzz\OcMonitor\Client\ErrorCatcher::normalize(['type' => E_ERROR, 'message' => "Uncaught Error: Call to undefined function foo() in /var/www/html/wp-content/plugins/x/x.php:12\nStack trace:\n#0 {main} geheim=123", 'file' => '/var/www/html/wp-content/plugins/x/x.php', 'line' => 12], '/winkel/?id=5&token=abc', '/var/www/html/');
    check($e['type'] === 'Fatal error');
    check($e['file'] === 'wp-content/plugins/x/x.php');
    check(strpos($e['message'], '/var/www') === false && strpos($e['message'], 'geheim') === false, $e['message']);
    check($e['url'] === '/winkel/', 'zonder querystring');
    $f = \NextBuzz\OcMonitor\Client\ErrorCatcher::normalize(['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted', 'file' => '/var/www/html/a.php', 'line' => 3], '/', '/var/www/html/');
    $g = \NextBuzz\OcMonitor\Client\ErrorCatcher::normalize(['type' => E_ERROR, 'message' => 'Allowed memory size of 268435456 bytes exhausted', 'file' => '/var/www/html/a.php', 'line' => 3], '/', '/var/www/html/');
    check($f['hash'] === $g['hash'], 'zelfde fout met andere getallen = één regel');
});

test('widget: downloadlink alleen naar het eigen panel, kleuren en datums gecontroleerd', function () {
    $W = '\\NextBuzz\\OcMonitor\\Client\\Admin\\DashboardWidget';
    $ok = $W::normalize(['next' => '2026-11-16', 'report_url' => 'https://oc.nextbuzz.nl/api/rapport/1?signature=x', 'color' => '#00aa55'], 'oc.nextbuzz.nl');
    check($ok['report_url'] === 'https://oc.nextbuzz.nl/api/rapport/1?signature=x' && $ok['color'] === '#00AA55' && $ok['next'] === '2026-11-16');
    check($W::normalize(['report_url' => 'https://phish.example/rapport.pdf'], 'oc.nextbuzz.nl')['report_url'] === null, 'andere host');
    check($W::normalize(['report_url' => 'http://oc.nextbuzz.nl/x'], 'oc.nextbuzz.nl')['report_url'] === null, 'geen https');
    check($W::normalize(['report_url' => 'https://oc.nextbuzz.nl/x'], null)['report_url'] === null, 'niet gekoppeld');
    $bad = $W::normalize(['next' => 'morgen', 'color' => 'red;}body{display:none', 'contact' => '<script>x</script>Bel ons'], 'oc.nextbuzz.nl');
    check($bad['next'] === null && $bad['color'] === '#E22830' && $bad['contact'] === 'xBel ons');
});

// ---------------------------------------------------------------- Opslag

test('opslag: mapgrootte telt bestanden in submappen en slaat ontbrekende mappen over', function () {
    $dir = sys_get_temp_dir() . '/nbocm-storage-' . bin2hex(random_bytes(4));
    mkdir($dir . '/a/b', 0777, true);
    file_put_contents($dir . '/x.txt', str_repeat('x', 100));
    file_put_contents($dir . '/a/b/y.txt', str_repeat('y', 50));
    $files = 0;
    check(\NextBuzz\OcMonitor\Client\Storage::directorySize($dir, microtime(true) + 10, $files) === 150 && $files === 2);
    check(\NextBuzz\OcMonitor\Client\Storage::directorySize($dir . '/bestaat-niet', microtime(true) + 10, $files) === null);
    unlink($dir . '/a/b/y.txt');
    unlink($dir . '/x.txt');
    rmdir($dir . '/a/b');
    rmdir($dir . '/a');
    rmdir($dir);
});

// ---------------------------------------------------------------- Self-update

test('self-update: laadt vooraf alle classes van de plugin, zodat het verzoek niet halverwege nieuwe bestanden leest', function () {
    if (!class_exists('WP_REST_Response')) {
        eval('class WP_REST_Response {}');
    }
    if (!defined('NB_OCM_FILE')) {
        define('NB_OCM_FILE', dirname(__DIR__) . '/nb-ocmonitor-client.php');
    }
    \NextBuzz\OcMonitor\Client\Commands\UpdateCommands::preloadOwnClasses();
    $loaded = array_merge(get_declared_classes(), get_declared_interfaces());
    foreach (['NextBuzz\OcMonitor\Client\Storage', 'NextBuzz\OcMonitor\Client\RawResponse', 'NextBuzz\OcMonitor\Protocol\Signer', 'NextBuzz\OcMonitor\Protocol\NonceStore'] as $class) {
        check(in_array($class, $loaded, true), $class);
    }
});

// ---------------------------------------------------------------- SMTP-module

test('smtp: schakelaar smtp_module staat standaard uit en is een bool', function () {
    check(\NextBuzz\OcMonitor\Client\Tweaks::normalize(null)['smtp_module'] === false);
    check(\NextBuzz\OcMonitor\Client\Tweaks::normalize(['smtp_module' => '1'])['smtp_module'] === true);
});

test('smtp: wachtwoord versleutelen en ontsleutelen; andere sleutel of rommel geeft null', function () {
    $key = sodium_crypto_secretbox_keygen();
    $enc = \NextBuzz\OcMonitor\Client\Smtp\Crypto::encryptWith($key, 'geheim-wachtwoord');
    check(strpos($enc, 'geheim') === false, 'leesbaar opgeslagen');
    check(\NextBuzz\OcMonitor\Client\Smtp\Crypto::decryptWith($key, $enc) === 'geheim-wachtwoord');
    check(\NextBuzz\OcMonitor\Client\Smtp\Crypto::decryptWith(sodium_crypto_secretbox_keygen(), $enc) === null, 'andere sleutel');
    check(\NextBuzz\OcMonitor\Client\Smtp\Crypto::decryptWith($key, 'geheim-wachtwoord') === null, 'onversleuteld');
    check(\NextBuzz\OcMonitor\Client\Smtp\Crypto::decryptWith($key, 'nbenc1:!!!') === null, 'kapot');
    check($enc !== \NextBuzz\OcMonitor\Client\Smtp\Crypto::encryptWith($key, 'geheim-wachtwoord'), 'nonce wordt hergebruikt');
});

test('smtp: instellingen worden gecontroleerd', function () {
    $o = \NextBuzz\OcMonitor\Client\Smtp\Options::normalize([
        'host' => ' SMTP.Example.NL ', 'port' => '99999', 'encryption' => 'rot13', 'from_email' => 'geen-adres',
        'from_name' => "Naam\r\nBcc: x@y.z", 'user' => 'info@example.nl', 'auth' => '0',
    ]);
    check($o['host'] === 'smtp.example.nl', 'host');
    check($o['port'] === 587, 'poort buiten bereik');
    check($o['encryption'] === 'tls', 'onbekende versleuteling');
    check($o['from_email'] === '', 'ongeldig adres');
    check(strpos($o['from_name'], "\n") === false && strpos($o['from_name'], "\r") === false, 'header-injectie via naam');
    check($o['auth'] === false);
    check(\NextBuzz\OcMonitor\Client\Smtp\Options::normalize(['host' => 'http://evil/x'])['host'] === '', 'url als host');
    check(\NextBuzz\OcMonitor\Client\Smtp\Options::isComplete(\NextBuzz\OcMonitor\Client\Smtp\Options::normalize(['host' => 'smtp.x.nl', 'auth' => true, 'user' => ''])) === false, 'auth zonder gebruiker');
    check(\NextBuzz\OcMonitor\Client\Smtp\Options::isComplete(\NextBuzz\OcMonitor\Client\Smtp\Options::normalize(['host' => 'smtp.x.nl', 'auth' => false])) === true);
    check(strpos(\NextBuzz\OcMonitor\Client\Smtp\Options::describe(\NextBuzz\OcMonitor\Client\Smtp\Options::normalize(['host' => 'smtp.x.nl', 'user' => 'u', 'pass' => 'nbenc1:abc'])), 'nbenc1') === false, 'wachtwoord in logregel');
});

test('smtp: foutdiagnose herkent de gangbare fouten', function () {
    $d = static function (string $error, string $debug = ''): string {
        return \NextBuzz\OcMonitor\Client\Smtp\Diagnose::explain($error, $debug)['code'];
    };
    check($d('SMTP Error: Could not authenticate.') === 'auth', 'auth');
    check($d('SMTP Error: Could not connect to SMTP host. Failed to connect to server', "Connection failed. Error #2: stream_socket_client(): Unable to connect to smtp.x.nl:587 (Connection timed out)") === 'timeout', 'timeout');
    check($d('SMTP Error: Could not connect to SMTP host.', "Connection failed. Error #2: stream_socket_client(): php_network_getaddresses: getaddrinfo for smtp.x.nl failed") === 'dns', 'dns');
    check($d('SMTP Error: Could not connect to SMTP host.', 'Connection failed. stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed') === 'certificate', 'certificaat');
    check($d('SMTP Error: Could not authenticate.', "SERVER -> CLIENT: 535 5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant.") === 'app_password', 'M365');
    check($d('SMTP Error: data not accepted.', "SERVER -> CLIENT: 554 5.7.1 Message rejected as spam") === 'rejected', 'spam');
    check($d('SMTP Error: The following From address failed: a@b.nl', "SERVER -> CLIENT: 553 5.7.1 Sender address rejected: not owned by user") === 'sender', 'afzender');
    check($d('Iets heel anders') === 'unknown', 'onbekend');
    // STARTTLS in de EHLO-lijst of een getal als SIZE 35882421 is geen fout.
    $ehlo = "SERVER -> CLIENT: 250-smtp.x.nl\nSERVER -> CLIENT: 250-SIZE 35882421\nSERVER -> CLIENT: 250-STARTTLS\nSERVER -> CLIENT: 250 AUTH LOGIN PLAIN";
    check($d('SMTP Error: Could not authenticate.', $ehlo . "\nSERVER -> CLIENT: 535 Incorrect authentication data") === 'auth', 'auth na EHLO met STARTTLS');
});

test('smtp: inloggegevens verdwijnen uit de SMTP-dialoog', function () {
    $user = 'info@example.nl';
    $pass = 'Sup3r$geheim';
    $debug = "CLIENT -> SERVER: AUTH LOGIN\nSERVER -> CLIENT: 334 VXNlcm5hbWU6\nCLIENT -> SERVER: " . base64_encode($user)
        . "\nSERVER -> CLIENT: 334 UGFzc3dvcmQ6\nCLIENT -> SERVER: " . base64_encode($pass)
        . "\nCLIENT -> SERVER: AUTH PLAIN " . base64_encode("\0" . $user . "\0" . $pass);
    $out = \NextBuzz\OcMonitor\Client\Smtp\TestMail::redact($debug, $user, $pass);
    check(strpos($out, base64_encode($pass)) === false && strpos($out, $pass) === false, 'wachtwoord');
    check(strpos($out, base64_encode("\0" . $user . "\0" . $pass)) === false, 'AUTH PLAIN');
    check(substr_count($out, '[verborgen]') >= 3);
    $other = \NextBuzz\OcMonitor\Client\Smtp\TestMail::redact("CLIENT -> SERVER: AUTH XOAUTH2 dG9rZW4=", $user, '');
    check(strpos($other, 'dG9rZW4=') === false, 'XOAUTH2-token');
});

test('smtp: andere mailplugins worden herkend', function () {
    $found = \NextBuzz\OcMonitor\Client\Smtp\Conflicts::detect(['akismet/akismet.php', 'wp-mail-smtp/wp_mail_smtp.php', 'fluent-smtp/fluent-smtp.php']);
    check($found === ['WP Mail SMTP', 'FluentSMTP']);
    check(\NextBuzz\OcMonitor\Client\Smtp\Conflicts::detect([]) === []);
});

test('dashboardwidget: eenmalig bovenaan de eerste kolom, uit de andere kolommen gehaald', function () {
    $W = \NextBuzz\OcMonitor\Client\Admin\DashboardWidget::class;
    $order = $W::moveToTop(['normal' => 'dashboard_activity,dashboard_right_now', 'side' => 'dashboard_quick_press,nb_ocm_maintenance', 'column3' => '', 'column4' => ''], 'nb_ocm_maintenance');
    check($order['normal'] === 'nb_ocm_maintenance,dashboard_activity,dashboard_right_now', $order['normal']);
    check($order['side'] === 'dashboard_quick_press', $order['side']);
    check($W::moveToTop(['normal' => 'nb_ocm_maintenance'], 'nb_ocm_maintenance')['normal'] === 'nb_ocm_maintenance', 'niet dubbel');
    check($W::moveToTop(['side' => 'a'], 'nb_ocm_maintenance')['normal'] === 'nb_ocm_maintenance', 'zonder normal-kolom');
});

test('smtp: standaardafzender van WordPress wordt herkend', function () {
    check(\NextBuzz\OcMonitor\Client\Smtp\Mailer::isDefaultWpFrom('wordpress@klant.nl'));
    check(\NextBuzz\OcMonitor\Client\Smtp\Mailer::isDefaultWpFrom(''));
    check(!\NextBuzz\OcMonitor\Client\Smtp\Mailer::isDefaultWpFrom('info@klant.nl'));
});

// ---- SMTP: backup

test('smtp backup: instellingen worden gecontroleerd en bevatten alleen verbindingsvelden', function () {
    $b = \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::normalize([
        'enabled' => '1', 'host' => ' Mail.Backup.NL ', 'port' => '0', 'encryption' => 'x', 'user' => "u@x.nl\r\n",
        'pass' => 'nbenc1:abc', 'from_email' => 'info@x.nl', 'force_from_email' => true,
    ]);
    check($b['enabled'] === true);
    check($b['host'] === 'mail.backup.nl', 'host');
    check($b['port'] === 587 && $b['encryption'] === 'tls', 'poort/versleuteling');
    check($b['user'] === 'u@x.nl', 'gebruiker');
    check($b['pass'] === 'nbenc1:abc', 'pass ongewijzigd');
    check(!isset($b['from_email']) && !isset($b['force_from_email']), 'geen afzendervelden');
    check(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::normalize(null)['enabled'] === false, 'standaard uit');
    check(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::normalize(['host' => 'http://evil/x'])['host'] === '', 'url als host');
});

test('smtp backup: compleet pas met server, gebruiker en wachtwoord', function () {
    $n = static function (array $v): array {
        return \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::normalize($v);
    };
    $complete = static function (array $v) use ($n): bool {
        return \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::isComplete($n($v));
    };
    check($complete(['host' => 'smtp.x.nl', 'auth' => true, 'user' => 'u', 'pass' => 'nbenc1:abc']) === true);
    check($complete(['host' => 'smtp.x.nl', 'auth' => true, 'user' => 'u', 'pass' => '']) === false, 'zonder wachtwoord');
    check($complete(['host' => 'smtp.x.nl', 'auth' => true, 'user' => '', 'pass' => 'nbenc1:abc']) === false, 'zonder gebruiker');
    check($complete(['host' => 'smtp.x.nl', 'auth' => false]) === true, 'relay zonder login');
    check($complete(['host' => '', 'auth' => false]) === false, 'zonder server');
    check(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::isUsable($n(['enabled' => false, 'host' => 'smtp.x.nl', 'auth' => false])) === false, 'uit');
    check(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::isUsable($n(['enabled' => true, 'host' => 'smtp.x.nl', 'auth' => false])) === true, 'aan zonder login');
});

test('smtp backup: opslaan houdt of wist het wachtwoord, mailer-opties nemen de afzender van primair', function () {
    $enc = static function (string $p): string {
        return 'ENC(' . $p . ')';
    };
    $stored = \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::normalize(['enabled' => true, 'host' => 'a.nl', 'pass' => 'nbenc1:oud']);
    $keep = \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::merge($stored, ['host' => 'b.nl', 'pass' => ''], $enc);
    check($keep['pass'] === 'nbenc1:oud' && $keep['host'] === 'b.nl', 'leeg veld houdt wachtwoord');
    check($keep['enabled'] === false && $keep['auth'] === false, 'niet aangevinkt = uit');
    check(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::merge($stored, ['pass' => 'nieuw'], $enc)['pass'] === 'ENC(nieuw)', 'nieuw wachtwoord versleuteld');
    check(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::merge($stored, ['pass' => 'nieuw', 'clear_pass' => '1'], $enc)['pass'] === '', 'wissen');
    $primary = \NextBuzz\OcMonitor\Client\Smtp\Options::normalize(['host' => 'primair.nl', 'user' => 'p', 'from_email' => 'info@klant.nl', 'return_path' => false]);
    $m = \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::mailerOptions($keep, $primary);
    check($m['host'] === 'b.nl' && $m['user'] === '' && $m['from_email'] === 'info@klant.nl' && $m['return_path'] === false, 'mailer-opties');
    check(strpos(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::describe($stored), 'nbenc1') === false, 'wachtwoord in logregel');
    check(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::describe(\NextBuzz\OcMonitor\Client\Smtp\Backup\BackupOptions::normalize([])) === 'uit');
});

test('smtp backup: testmails en meldingsmails worden herkend, backup-header wordt toegevoegd', function () {
    $bm = \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupModule::class;
    check($bm::shouldSkip(['Content-Type: text/html', \NextBuzz\OcMonitor\Client\Smtp\TestMail::HEADER]) === true, 'testmail (lijst)');
    check($bm::shouldSkip("From: a@b.nl\r\nx-nb-ocm-alert: 1") === true, 'melding (string, kleine letters)');
    check($bm::shouldSkip("From: a@b.nl\nSubject-X: X-NB-OCM-Test: 1") === false, 'alleen echte headernaam');
    check($bm::shouldSkip('') === false && $bm::shouldSkip([]) === false, 'leeg');
    check($bm::withHeader('') === 'X-NB-OCM-Connection: backup', 'lege string');
    check($bm::withHeader("From: a@b.nl\r\n") === "From: a@b.nl\r\nX-NB-OCM-Connection: backup", 'string');
    check($bm::withHeader(['From: a@b.nl']) === ['From: a@b.nl', 'X-NB-OCM-Connection: backup'], 'lijst');
    check($bm::withHeader(['X-NB-OCM-Connection: backup']) === ['X-NB-OCM-Connection: backup'], 'niet dubbel');
});

test('smtp backup: wp_mail-argumenten worden gelezen (associatief of op volgorde)', function () {
    $bm = \NextBuzz\OcMonitor\Client\Smtp\Backup\BackupModule::class;
    $a = $bm::mailArgs(['to' => 'a@b.nl', 'subject' => 'Hoi', 'message' => 'Tekst', 'headers' => ['X: 1'], 'attachments' => ['/tmp/f']]);
    check($a !== null && $a['to'] === 'a@b.nl' && $a['subject'] === 'Hoi' && $a['headers'] === ['X: 1'] && $a['attachments'] === ['/tmp/f']);
    $p = $bm::mailArgs(['a@b.nl', 'Hoi', 'Tekst']);
    check($p !== null && $p['headers'] === '' && $p['attachments'] === [], 'op volgorde, aangevuld');
    check($bm::mailArgs([]) === null && $bm::mailArgs(['to' => '']) === null, 'zonder ontvanger');
    check($bm::isRunning() === false);
});

// ---- SMTP: meldingen

test('smtp meldingen: ontvangers en webhooks worden gecontroleerd (max 3, alleen https)', function () {
    $S = \NextBuzz\OcMonitor\Client\Smtp\Alerts\AlertSettings::class;
    $e = $S::parseEmails(['a@klant.nl', ' ', 'geen-adres', 'A@klant.nl', 'b@klant.nl', 'c@klant.nl', 'd@klant.nl']);
    check($e['valid'] === ['a@klant.nl', 'b@klant.nl', 'c@klant.nl'], 'geldige, uniek, max 3');
    check(in_array('geen-adres', $e['invalid'], true) && in_array('d@klant.nl', $e['invalid'], true), 'ongeldig en 4e adres gemeld');
    $u = $S::parseUrls(['https://hooks.slack.com/services/T/B/x', 'http://voorbeeld.nl/hook', 'ftp://x.nl', 'https://', 'javascript:alert(1)', 'https://a.nl/x y']);
    check($u['valid'] === ['https://hooks.slack.com/services/T/B/x'], 'alleen https');
    check(count($u['invalid']) === 5, 'http://, ftp, zonder host, javascript, spatie geweigerd');
    check($S::isWebhookUrl('HTTPS://Example.com/hook'), 'schema hoofdletterongevoelig');
    check(!$S::isWebhookUrl('http://localhost/hook'), 'http localhost');
    check($S::webhookLabel('https://hooks.slack.com/services/T/B/geheim') === 'hooks.slack.com', 'geheim pad niet in geschiedenis');
    $n = $S::normalize(['throttle' => 7, 'events' => ['failed' => false]]);
    check($n['throttle'] === 15 && $n['events'] === ['failed' => false, 'backup_used' => true, 'recovered' => true], 'normalize');
    check($S::normalize(['throttle' => '240'])['throttle'] === 240, 'throttle 240');
});

test('smtp meldingen: throttling bundelt fouten en telt wat is ingehouden', function () {
    $A = \NextBuzz\OcMonitor\Client\Smtp\Alerts\AlertState::class;
    $on = ['failed' => true, 'backup_used' => true, 'recovered' => true];
    $fail = ['log_id' => 7, 'status' => 'failed', 'connection' => 'primary', 'to' => ['x@y.nl'], 'subject' => 'Hoi', 'error' => 'SMTP Error: Could not authenticate.', 'backup_error' => '', 'diagnose_code' => 'auth', 'initiator' => 'Contact Form 7', 'is_test' => false, 'time' => NOW];

    $o = $A::apply([], ['is_test' => true] + $fail, NOW, 15, $on);
    check($o['emit'] === [] && $o['state']['failing'] === 0, 'testmail genegeerd');

    $o = $A::apply([], $fail, NOW, 15, $on);
    check(count($o['emit']) === 1 && $o['emit'][0]['type'] === 'failed' && $o['emit'][0]['suppressed'] === 0, 'eerste fout direct');
    check($o['emit'][0]['panel'] && $o['emit'][0]['notify'], 'panel en kanalen');
    $s = $o['state'];

    $o = $A::apply($s, $fail, NOW + 60, 15, $on);
    check($o['emit'] === [], 'tweede binnen venster ingehouden');
    check($o['schedule'] === [['sig' => 'failed:auth', 'at' => NOW + 900]], 'flush gepland aan eind van venster');
    $o = $A::apply($o['state'], ['log_id' => 9] + $fail, NOW + 120, 15, $on);
    check($o['emit'] === [] && $o['schedule'] === [], 'derde: geen tweede flush');
    check($o['state']['throttle']['failed:auth']['count'] === 2 && $o['state']['throttle']['failed:auth']['last']['log_id'] === 9, 'geteld, laatste bewaard');

    // Andere oorzaak = eigen signatuur, dus wel direct.
    $other = $A::apply($o['state'], ['diagnose_code' => 'timeout'] + $fail, NOW + 130, 15, $on);
    check(count($other['emit']) === 1 && $other['emit'][0]['suppressed'] === 0, 'andere diagnose direct');

    // Flush te vroeg: opnieuw plannen; op tijd: een samenvatting.
    $early = $A::flush($o['state'], 'failed:auth', NOW + 600, 15, $on);
    check($early['emit'] === [] && $early['schedule'][0]['at'] === NOW + 900, 'te vroeg');
    $f = $A::flush($o['state'], 'failed:auth', NOW + 905, 15, $on);
    check(count($f['emit']) === 1 && $f['emit'][0]['digest'] === true && $f['emit'][0]['suppressed'] === 2 && $f['emit'][0]['since'] === NOW + 60, 'samenvatting');
    check($f['emit'][0]['result']['log_id'] === 9, 'samenvatting met de laatste mail');
    check($A::flush($f['state'], 'failed:auth', NOW + 2000, 15, $on)['emit'] === [], 'niets meer open');

    // Geen flush gedraaid (cron uit) en het venster is voorbij: de volgende fout neemt de telling mee.
    $o2 = $A::apply($o['state'], $fail, NOW + 1000, 15, $on);
    check(count($o2['emit']) === 1 && $o2['emit'][0]['suppressed'] === 2 && $o2['emit'][0]['since'] === NOW + 60, '+2 bij de volgende melding');
    check($A::flush($o2['state'], 'failed:auth', NOW + 1001, 15, $on)['emit'] === [], 'geplande flush doet dan niets');
});

test('smtp meldingen: backup gebruikt en herstel', function () {
    $A = \NextBuzz\OcMonitor\Client\Smtp\Alerts\AlertState::class;
    $on = ['failed' => true, 'backup_used' => true, 'recovered' => true];
    $ok = ['status' => 'sent', 'connection' => 'primary', 'to' => ['x@y.nl'], 'subject' => 'Hoi', 'error' => '', 'diagnose_code' => '', 'is_test' => false, 'time' => NOW];
    $failed = ['status' => 'failed', 'diagnose_code' => 'auth'] + $ok;

    check($A::apply([], $ok, NOW, 15, $on)['emit'] === [], 'gewone mail: niets');
    $b = $A::apply([], ['connection' => 'backup', 'error' => 'SMTP connect() failed', 'diagnose_code' => 'connect'] + $ok, NOW, 15, $on);
    check($b['emit'][0]['type'] === 'backup_used', 'backup_used');

    $s = $A::apply([], $failed, NOW, 15, $on)['state'];
    $s = $A::apply($s, $failed, NOW + 10, 15, $on)['state'];
    $r = $A::apply($s, $ok, NOW + 300, 15, $on);
    check(count($r['emit']) === 1 && $r['emit'][0]['type'] === 'recovered' && $r['emit'][0]['failures'] === 2 && $r['emit'][0]['since'] === NOW, 'herstel na 2 fouten');
    check($r['emit'][0]['notify'] && $r['emit'][0]['panel'], 'herstel naar panel en kanalen');
    check($r['state']['failing'] === 0 && $r['state']['throttle'] === [], 'schone lei');
    check($A::apply($r['state'], $ok, NOW + 400, 15, $on)['emit'] === [], 'geen tweede herstel');
    check($A::apply($r['state'], $failed, NOW + 400, 15, $on)['emit'] !== [], 'nieuw incident meteen gemeld');

    // Kanalen uit voor failed: het herstel gaat dan ook niet naar de kanalen, wel naar het panel.
    $off = ['failed' => false, 'backup_used' => true, 'recovered' => true];
    $s = $A::apply([], $failed, NOW, 15, $off);
    check($s['emit'][0]['notify'] === false && $s['emit'][0]['panel'] === true, 'failed uit: alleen panel');
    $r = $A::apply($s['state'], $ok, NOW + 60, 15, $off);
    check($r['emit'][0]['type'] === 'recovered' && $r['emit'][0]['notify'] === false && $r['emit'][0]['panel'] === true, 'herstel alleen naar het panel');
});

test('smtp meldingen: webhook-JSON, tekst en e-mail', function () {
    $P = \NextBuzz\OcMonitor\Client\Smtp\Alerts\Payload::class;
    $site = ['title' => 'Klant BV', 'url' => 'https://klant.nl', 'admin_url' => 'https://klant.nl/wp-admin/', 'panel_url' => null];
    $emit = ['type' => 'failed', 'suppressed' => 12, 'since' => NOW, 'digest' => false, 'result' => [
        'log_id' => 42, 'status' => 'failed', 'connection' => 'primary', 'to' => ['a@b.nl'], 'subject' => '<script>x</script>',
        'error' => 'SMTP Error: Could not authenticate.', 'backup_error' => '', 'diagnose_code' => 'auth', 'initiator' => 'WooCommerce', 'time' => NOW,
    ]];
    $body = $P::webhook($emit, $site);
    check(array_keys($body) === ['event', 'site_title', 'site_url', 'message', 'text', 'mail', 'suppressed'], 'velden zonder panel_url');
    check($body['event'] === 'smtp.failed' && $body['suppressed'] === 12, 'event en suppressed');
    check(array_keys($body['mail']) === ['to', 'subject', 'error', 'backup_error', 'diagnose_code', 'diagnose_title', 'initiator', 'log_id', 'time'], 'mailvelden');
    check($body['mail']['diagnose_title'] === 'Inloggen bij de SMTP-server lukt niet' && $body['mail']['log_id'] === 42 && $body['mail']['time'] === gmdate('c', NOW), 'mailwaarden');
    check(strpos($body['message'], '+12 vergelijkbare fouten sinds') !== false, 'telling in de tekst: ' . $body['message']);
    check(isset($P::webhook($emit, ['panel_url' => 'https://oc.nextbuzz.nl'] + $site)['panel_url']), 'panel_url als gekoppeld');
    check($P::subject($emit, $site) === '[Klant BV] E-mail niet verstuurd (+12)', 'onderwerp');
    check($P::logUrl('https://klant.nl/wp-admin/', 42) === 'https://klant.nl/wp-admin/admin.php?page=nb-ocmonitor&tab=maillog&log=42', 'link naar logregel');

    $digest = $P::message(['digest' => true, 'suppressed' => 3] + $emit, $site);
    check(strpos($digest, 'In totaal 3 vergelijkbare fouten') !== false, 'samenvatting');
    $rec = $P::message(['type' => 'recovered', 'failures' => 1, 'since' => NOW, 'suppressed' => 0, 'result' => []], $site);
    check(strpos($rec, 'weer verstuurd') !== false && strpos($rec, '1 e-mail niet') !== false, 'herstel: ' . $rec);

    $html = \NextBuzz\OcMonitor\Client\Smtp\Alerts\EmailBody::html($emit, $site);
    check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'onderwerp ge-escapet');
    check(strpos($html, '#1F2937') !== false && strpos($html, '#E22830') !== false, 'Next Buzz-stijl');
    check(strpos($html, 'tab=maillog&amp;log=42') !== false, 'link in de mail');
    check(strpos($html, 'app-wachtwoord') !== false, 'stappen uit Diagnose');
});

test('smtp meldingen: geschiedenis, adminwaarschuwing en omweg buiten SMTP', function () {
    $H = \NextBuzz\OcMonitor\Client\Smtp\Alerts\History::class;
    $list = [];
    for ($i = 1; $i < 56; $i++) {
        $list = $H::push($list, ['time' => $i, 'type' => 'failed', 'channel' => 'email', 'target' => 'a@b.nl', 'ok' => true]);
    }
    check(count($list) === 50 && $list[0]['time'] === 55 && $list[49]['time'] === 6, 'ringbuffer 50, nieuwste eerst');

    $N = \NextBuzz\OcMonitor\Client\Smtp\Alerts\ProblemNotice::class;
    $p = $N::next(null, ['status' => 'failed', 'log_id' => 3], NOW);
    check($p === ['since' => NOW, 'last' => NOW, 'count' => 1, 'log_id' => 3], 'probleem gezet');
    $p = $N::next($p, ['status' => 'failed', 'log_id' => 4], NOW + 5);
    check($p['since'] === NOW && $p['last'] === NOW + 5 && $p['count'] === 2, 'opgeteld');
    check($N::next($p, ['status' => 'sent'], NOW + 9) === null, 'weg bij succes');

    $M = \NextBuzz\OcMonitor\Client\Smtp\Alerts\AlertsModule::class;
    check($M::bypassFor(['type' => 'failed', 'result' => ['connection' => 'primary']], true), 'primaire SMTP faalde: via mail()');
    check($M::bypassFor(['type' => 'backup_used', 'result' => ['connection' => 'backup']], true), 'backup gebruikt: via mail()');
    check(!$M::bypassFor(['type' => 'failed', 'result' => ['connection' => 'wordpress']], true), 'connection wordpress');
    check(!$M::bypassFor(['type' => 'recovered', 'result' => ['connection' => 'primary']], true), 'herstel via SMTP');
    check(!$M::bypassFor(['type' => 'failed', 'result' => ['connection' => 'primary']], false), 'module niet actief');
    $t = $M::testEmit(NOW, 'rick@x.nl');
    check($t['type'] === 'test' && $t['result']['to'] === ['rick@x.nl'] && $t['panel'] === false, 'testmelding');
});

// ---- SMTP: e-maillog

test('maillog: ontvangers uit to (string of array) en headers, ook met komma in de naam', function () {
    $P = \NextBuzz\OcMonitor\Client\Smtp\Log\MailParts::class;
    check($P::addresses('a@x.nl, "Jansen, Piet" <p@x.nl>,, b@x.nl') === ['a@x.nl', '"Jansen, Piet" <p@x.nl>', 'b@x.nl'], 'string');
    check($P::emails(['Piet <p@x.nl>', 'q@x.nl', null]) === ['p@x.nl', 'q@x.nl'], 'array');
    check($P::email('"Jansen, Piet" <p@x.nl>') === 'p@x.nl');
    $h = $P::parseHeaders("From: Shop <shop@x.nl>\r\nCc: c1@x.nl, C Twee <c2@x.nl>\r\nBCC: b@x.nl\r\nContent-Type: text/html; charset=UTF-8\r\nX-NB-OCM-Test: 1\r\nX-Lang: een\r\n  vervolg");
    check($h['cc'] === ['c1@x.nl', 'C Twee <c2@x.nl>'] && $h['bcc'] === ['b@x.nl'], 'cc/bcc');
    check($h['from'] === 'Shop <shop@x.nl>' && $h['content_type'] === 'text/html', 'from/type');
    check($h['test'] === true && $h['alert'] === false && $h['connection'] === '', 'vlaggen');
    check(end($h['lines']) === 'X-Lang: een vervolg', 'vervolgregel');
    $a = $P::parseHeaders(['X-NB-OCM-Alert: 1', 'X-NB-OCM-Connection: backup', 'Cc: z@x.nl']);
    check($a['alert'] === true && $a['connection'] === 'backup' && $a['cc'] === ['z@x.nl'], 'headers als array');
    check($P::parseHeaders(null)['lines'] === [], 'geen headers');
});

test('maillog: headers voor opnieuw versturen zonder eigen headers en eventueel zonder Cc/Bcc', function () {
    $P = \NextBuzz\OcMonitor\Client\Smtp\Log\MailParts::class;
    $lines = ['Content-Type: text/html', 'X-NB-OCM-Test: 1', 'Cc: c@x.nl', 'bcc: b@x.nl', 'Reply-To: r@x.nl'];
    check($P::resendHeaders($lines, false) === ['Content-Type: text/html', 'Cc: c@x.nl', 'bcc: b@x.nl', 'Reply-To: r@x.nl']);
    check($P::resendHeaders($lines, true) === ['Content-Type: text/html', 'Reply-To: r@x.nl']);
});

test('maillog: bijlagen alleen als bestandsnaam', function () {
    $P = \NextBuzz\OcMonitor\Client\Smtp\Log\MailParts::class;
    check($P::attachmentNames(['/var/www/up/factuur.pdf', 'C:\\tmp\\a.txt', 'Eigen naam.pdf' => '/tmp/x', '']) === ['factuur.pdf', 'a.txt', 'Eigen naam.pdf']);
    check($P::attachmentNames("/a/b.csv\n/c/d.zip") === ['b.csv', 'd.zip']);
    check($P::attachmentNames(null) === []);
});

test('maillog: voorbeeldweergave blokkeert externe bronnen en escapet platte tekst', function () {
    $P = \NextBuzz\OcMonitor\Client\Smtp\Log\MailParts::class;
    $html = $P::previewDocument('<html><head><title>x</title></head><body><img src="https://track.er/p.gif"></body></html>', 'text/html');
    check(strpos($html, '<head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'') !== false, 'CSP direct na <head>');
    check(strpos($P::previewDocument('<p>los</p>', 'text/html'), '<meta http-equiv="Content-Security-Policy"') === 0, 'CSP zonder head');
    $plain = $P::previewDocument('<script>alert(1)</script>', 'text/plain');
    check(strpos($plain, '<script>') === false && strpos($plain, '&lt;script&gt;') !== false, 'tekst ge-escapet');
});

test('maillog: bron (initiator) uit het pad: plugin, mu-plugin, thema, WP Core, onbekend', function () {
    $I = \NextBuzz\OcMonitor\Client\Smtp\Log\Initiator::class;
    $dirs = ['plugins' => 'C:\\www\\wp-content\\plugins', 'mu' => '/www/wp-content/mu-plugins', 'themes' => '/www/wp-content/themes', 'core' => '/www/'];
    $names = $I::namesFromPlugins(['contact-form-7/wp-contact-form-7.php' => ['Name' => 'Contact Form 7'], 'hello.php' => ['Name' => 'Hello Dolly']]);
    check($names === ['contact-form-7' => 'Contact Form 7', 'hello.php' => 'Hello Dolly'], 'namen');
    $c = static function (string $file) use ($I, $dirs, $names): array {
        $r = $I::classify($file, $dirs, $names);

        return [$r['type'], $r['name']];
    };
    check($c('C:/www/wp-content/plugins/contact-form-7/includes/mail.php') === ['plugin', 'Contact Form 7'], 'plugin (Windows-pad)');
    check($c('c:\\WWW\\wp-content\\plugins\\hello.php') === ['plugin', 'Hello Dolly'], 'losse plugin, hoofdletters');
    check($c('C:/www/wp-content/plugins/onbekend-ding/x.php') === ['plugin', 'onbekend-ding'], 'plugin zonder naam');
    check($c('/www/wp-content/mu-plugins/loader.php') === ['mu-plugin', 'loader.php (mu-plugin)'], 'mu-plugin');
    check($c('/www/wp-content/themes/astra/functions.php') === ['theme', 'astra'], 'thema');
    check($c('/www/wp-includes/pluggable.php') === ['core', 'WP Core'], 'core');
    check($c('/www/wp-login.php') === ['core', 'WP Core'], 'core root');
    check($c('/www/wp-content/uploads/x.php') === ['unknown', 'onbekend'], 'wp-content elders');
    check($c('/elders/x.php') === ['unknown', 'onbekend'], 'buiten WordPress');
    check($c('/www/wp-content/pluginsextra/x.php')[0] === 'unknown', 'geen prefix-verwarring');
    check($I::relative('/www/wp-content/plugins/a/b.php', '/www/') === 'wp-content/plugins/a/b.php', 'relatief pad');
    $frame = $I::callerFrame([
        ['function' => 'detect', 'class' => 'X', 'file' => '/a.php', 'line' => 1],
        ['function' => 'apply_filters', 'file' => '/www/wp-includes/pluggable.php', 'line' => 2],
        ['function' => 'wp_mail', 'file' => '/www/wp-content/plugins/cf7/mail.php', 'line' => 42],
    ]);
    check($frame === ['file' => '/www/wp-content/plugins/cf7/mail.php', 'line' => 42], 'wp_mail-frame');
    check($I::callerFrame([['function' => 'x']]) === null, 'geen wp_mail');
});

test('maillog: CSV-cellen tegen formule-injectie en met juiste quotes', function () {
    $E = \NextBuzz\OcMonitor\Client\Smtp\Log\Export::class;
    check($E::csvCell('=HYPERLINK("x")') === '"\'=HYPERLINK(""x"")"', '=');
    foreach (['+31', '-1', '@SUM(A1)', "\tx", "\rx"] as $bad) {
        check(substr($E::csvCell($bad), 0, 2) === '"\'', 'prefix voor ' . json_encode($bad));
    }
    check($E::csvCell('gewoon; tekst') === '"gewoon; tekst"', 'normaal');
    check($E::csvCell('') === '""', 'leeg');
    check($E::csvLine(['a', 'b"c']) === "\"a\";\"b\"\"c\"\r\n", 'regel');
    $values = $E::csvValues(['id' => 5, 'status' => 'sent', 'connection' => 'backup', 'to_email' => 'a@x.nl', 'subject' => '=1+1', 'is_test' => 1], '2026-01-01 10:00:00');
    check(count($values) === count($E::csvColumns()), 'aantal kolommen');
    check($values[2] === 'Via backup' && $values[3] === 'Backup' && $values[13] === 'ja', 'labels');
});

test('maillog: EML-export', function () {
    $E = \NextBuzz\OcMonitor\Client\Smtp\Log\Export::class;
    $eml = $E::eml(['id' => 7, 'created_at' => NOW, 'from_email' => 'Klant BV <noreply@klant.nl>', 'to_email' => 'Piet Jansen <p@x.nl>, q@x.nl', 'cc' => 'c@x.nl', 'bcc' => 'geheim@x.nl',
        'subject' => "Bestelling ontvangen – €12\r\nBcc: evil@x.nl", 'message_id' => '<abc@klant.nl>', 'content' => '<p>Hallo</p>', 'content_type' => 'text/html']);
    [$head, $body] = explode("\r\n\r\n", $eml, 2);
    check(strpos($head, 'Date: Thu, 01 Jan 2026 00:00:00 +0000') !== false, 'datum');
    check(strpos($head, "To: Piet Jansen <p@x.nl>, q@x.nl") !== false && strpos($head, 'Cc: c@x.nl') !== false, 'ontvangers');
    check(strpos($eml, 'geheim@x.nl') === false, 'geen Bcc');
    check(preg_match('/^Subject: =\?UTF-8\?B\?/m', $head) === 1, 'onderwerp gecodeerd');
    check(preg_match('/^Bcc:/mi', $head) === 0, 'geen header-injectie via onderwerp');
    $decoded = '';
    preg_match_all('/=\?UTF-8\?B\?([^?]+)\?=/', $head, $m);
    foreach ($m[1] as $part) {
        $decoded .= base64_decode($part);
    }
    check(strpos($decoded, 'Bestelling ontvangen – €12') === 0, 'onderwerp terug te lezen');
    check(strpos($head, 'Content-Type: text/html; charset=UTF-8') !== false && strpos($head, 'Message-ID: <abc@klant.nl>') !== false, 'type/id');
    check(base64_decode(str_replace("\r\n", '', $body)) === '<p>Hallo</p>', 'body');
    $none = $E::eml(['id' => 8, 'created_at' => NOW, 'to_email' => 'a@x.nl', 'subject' => 'x', 'content' => null]);
    check(strpos($none, 'Content-Type: text/plain') !== false && strpos(base64_decode(explode("\r\n\r\n", $none, 2)[1]), 'niet gelogd') !== false, 'zonder inhoud');
    foreach (explode("\r\n", $head) as $line) {
        check(strlen($line) <= 998, 'regellengte');
    }
});

test('maillog: instellingen en bewaartermijn', function () {
    $S = \NextBuzz\OcMonitor\Client\Smtp\Log\LogSettings::class;
    check($S::normalize(null) === ['enabled' => true, 'log_content' => false, 'retention_days' => 30], 'standaard');
    check($S::normalize(['retention_days' => '45'])['retention_days'] === 30, 'ongeldige termijn');
    check($S::normalize(['retention_days' => '0'])['retention_days'] === 0, 'altijd bewaren');
    check($S::fromForm(['retention_days' => '365']) === ['enabled' => false, 'log_content' => false, 'retention_days' => 365], 'formulier zonder vinkjes');
    check($S::cutoff(NOW, 30) === NOW - 30 * 86400, 'cutoff');
    check($S::cutoff(NOW, 0) === null, 'altijd bewaren: geen cutoff');
});

test('maillog: filters, datumbereik en WHERE met placeholders', function () {
    $Q = \NextBuzz\OcMonitor\Client\Smtp\Log\LogQuery::class;
    $f = $Q::normalize(['status' => 'drop table', 's' => "a\nb", 'from' => '2026-02-30', 'to' => '2026-03-01', 'paged' => '-4']);
    check($f === ['status' => 'all', 's' => 'a b', 'from' => '', 'to' => '2026-03-01', 'paged' => 1], 'normaliseren');
    check($Q::where($Q::normalize([]), null, null) === ['1=1', []], 'geen filters');
    [$start, $end] = $Q::range('2026-03-01', '2026-03-01', new \DateTimeZone('Europe/Amsterdam'));
    check($start === 1772319600 && $end === $start + 86400, 'hele dag in de tijdzone van de site');
    [$sql, $args] = $Q::where(['status' => 'backup', 's' => '50%_x', 'from' => '', 'to' => '', 'paged' => 1], 10, 20);
    check($sql === 'status = %s AND connection = %s AND (to_email LIKE %s OR subject LIKE %s OR error LIKE %s OR initiator LIKE %s) AND created_at >= %d AND created_at < %d', 'sql');
    check($args === ['sent', 'backup', '%50\\%\\_x%', '%50\\%\\_x%', '%50\\%\\_x%', '%50\\%\\_x%', 10, 20], 'args met ge-escapete LIKE');
    check(substr_count($sql, '%') === count($args), 'evenveel placeholders als waarden');
    check($Q::where(['status' => 'test', 's' => '', 'from' => '', 'to' => '', 'paged' => 1], null, null) === ['is_test = %d', [1]], 'test');
    check($Q::pages(0) === 1 && $Q::pages(50) === 1 && $Q::pages(51) === 2 && $Q::offset(3) === 100, 'paginering');
    check($Q::args(['status' => 'all', 's' => '', 'from' => '2026-01-01', 'to' => '', 'paged' => 3], true) === ['from' => '2026-01-01', 'paged' => 3], 'link-argumenten');
});

// ---- SMTP: domeincheck en grafiek

test('domeincheck: afzenderdomein en records herkennen', function () {
    $D = \NextBuzz\OcMonitor\Client\Smtp\DomainCheck::class;
    check($D::senderDomain(['from_email' => 'Info@Klant.NL'], 'admin@x.nl') === 'klant.nl', 'domein van afzender');
    check($D::senderDomain(['from_email' => ''], 'beheer@site.example.com') === 'site.example.com', 'terugval op beheerdersadres');
    check($D::emailDomain('a@localhost') === '' && $D::emailDomain('geen-adres') === '' && $D::emailDomain('a@x"<y>.nl') === '', 'ongeldig domein');
    check($D::spfRecords(['google-site-verification=abc', ' v=spf1 -all', 'v=spf10 x']) === ['v=spf1 -all'], 'spf-records');
    check($D::dmarcRecords(['v=DMARC1; p=none', 'v=DMARC1x', 'x']) === ['v=DMARC1; p=none'], 'dmarc-records');
    check($D::spfIncludes('v=spf1 a mx include:SPF.Protection.Outlook.com. ~include:_spf.google.com redirect=_spf.x.nl -all') === ['spf.protection.outlook.com', '_spf.google.com', '_spf.x.nl'], 'includes');
    check($D::provider('smtp.office365.com')[1] === ['spf.protection.outlook.com'] && $D::provider('smtp-relay.gmail.com')[0] === 'Google Workspace', 'provider');
    check($D::provider('email-smtp.eu-west-1.amazonaws.com')[1] === ['amazonses.com'] && $D::provider('smtp.transip.email') === null && $D::provider('') === null, 'provider onbekend');
    check($D::looksLikeDkim('v=DKIM1; k=rsa; p=MIGf') && $D::looksLikeDkim('k=rsa; p=abc') && !$D::looksLikeDkim('v=spf1 -all'), 'dkim herkennen');
    check($D::truncate(str_repeat('a', 100), 10) === str_repeat('a', 9) . '…' && $D::truncate('kort') === 'kort', 'afkappen');
});

test('domeincheck: SPF beoordelen', function () {
    $D = \NextBuzz\OcMonitor\Client\Smtp\DomainCheck::class;
    check($D::spf([], 'smtp.office365.com')['tone'] === 'crit', 'geen record = fout');
    check($D::spf(['v=spf1 -all', 'v=spf1 ~all'], '')['verdict'] === 'Meerdere records', 'twee records = fout');
    check($D::spf(null, '')['tone'] === 'muted', 'dns-fout');
    check($D::spf(['v=spf1 include:spf.protection.outlook.com -all'], 'smtp.office365.com')['tone'] === 'ok', 'microsoft met -all');
    check($D::spf(['v=spf1 include:_spf.google.com ~all'], 'smtp.gmail.com')['tone'] === 'ok', 'google met ~all');
    check($D::spf(['v=spf1 include:_spf.google.com +all'], 'smtp.gmail.com')['tone'] === 'warn', '+all = let op');
    check($D::spf(['v=spf1 include:_spf.google.com all'], 'smtp.gmail.com')['tone'] === 'warn', 'kale all = +all');
    check($D::spf(['v=spf1 include:_spf.google.com ?all'], 'smtp.gmail.com')['tone'] === 'warn', '?all = let op');
    check($D::spf(['v=spf1 mx'], '')['tone'] === 'warn', 'geen all');
    check($D::spf(['v=spf1 redirect=_spf.host.nl'], '')['tone'] === 'ok', 'redirect is geldig');
    $missing = $D::spf(['v=spf1 mx ~all'], 'smtp.office365.com');
    check($missing['tone'] === 'warn' && strpos($missing['text'], 'include:spf.protection.outlook.com') !== false, 'provider ontbreekt');
    check($D::spf(['v=spf1 include:u123.wl.sendgrid.net ~all'], 'smtp.sendgrid.net')['tone'] === 'ok', 'subdomein van verwachte include');
    check($D::spf(['v=spf1 include:spf.host.nl ~all'], 'smtp.office365.com', ['spf.host.nl' => ['v=spf1 include:spf.protection.outlook.com ~all']])['tone'] === 'ok', 'één niveau diep');
    check($D::spf(['v=spf1 include:spf.host.nl ~all'], 'smtp.office365.com', ['spf.host.nl' => ['v=spf1 include:other.nl ~all']])['tone'] === 'warn', 'niet dieper dan één niveau');
    $unknown = $D::spf(['v=spf1 include:_spf.transip.email ~all'], 'smtp.transip.email');
    check($unknown['tone'] === 'ok' && strpos($unknown['text'], 'controleer bij je provider') !== false, 'onbekende provider is neutraal');
    check($D::spf(['v=spf1 +all'], 'smtp.office365.com')['tone'] === 'warn', 'warn blijft warn');
});

test('domeincheck: DMARC, DKIM en evaluate', function () {
    $D = \NextBuzz\OcMonitor\Client\Smtp\DomainCheck::class;
    check($D::dmarc([])['tone'] === 'crit' && $D::dmarc(null)['tone'] === 'muted', 'geen dmarc / dns-fout');
    check($D::dmarc(['v=DMARC1; p=none; rua=mailto:d@x.nl'])['tone'] === 'warn', 'p=none = alleen monitoren');
    check($D::dmarc(['v=DMARC1; p=Quarantine'])['verdict'] === 'p=quarantine' && $D::dmarc(['v=DMARC1;p=reject;pct=50'])['tone'] === 'ok', 'quarantine/reject');
    check(strpos($D::dmarc(['v=DMARC1;p=reject;pct=50'])['text'], '50%') !== false, 'pct genoemd');
    check($D::dmarc(['v=DMARC1; rua=mailto:x@y.nl'])['tone'] === 'crit' && $D::dmarc(['v=DMARC1; p=none', 'v=DMARC1; p=reject'])['tone'] === 'crit', 'ongeldig of dubbel');
    check($D::dmarcTags('v=DMARC1; P = reject ; rua=mailto:a@b.nl') === ['v' => 'DMARC1', 'p' => 'reject', 'rua' => 'mailto:a@b.nl'], 'tags');
    check($D::dkim([])['verdict'] === 'Niet gevonden' && strpos($D::dkim([])['text'], 'gangbare selectors') !== false, 'dkim niet gevonden');
    $ok = $D::dkim(['selector1' => 'CNAME → selector1-klant-nl._domainkey.klant.onmicrosoft.com', 'google' => 'v=DKIM1; k=rsa; p=MIIB']);
    check($ok['tone'] === 'ok' && count($ok['records']) === 2 && strpos($ok['text'], 'selector1, google') !== false, 'dkim gevonden');
    check($D::dkim(['default' => 'v=DKIM1; p='])['verdict'] === 'Ingetrokken', 'lege sleutel');
    check($D::dkim(['x' => 'v=DKIM1; p=a'], false)['tone'] === 'muted', 'zonder dns');
    $all = $D::evaluate(['available' => false], 'smtp.gmail.com');
    check(array_column($all, 'label') === ['SPF', 'DKIM', 'DMARC'] && array_unique(array_column($all, 'tone')) === ['muted'], 'zonder dns_get_record: alles onbekend');
    $all = $D::evaluate(['available' => true, 'spf' => ['v=spf1 include:_spf.google.com -all'], 'includes' => [], 'dmarc' => ['v=DMARC1; p=reject'], 'dkim' => ['google' => 'v=DKIM1; p=abc']], 'smtp.gmail.com');
    check(array_column($all, 'tone') === ['ok', 'ok', 'ok'], 'alles in orde');
    check(count(array_unique($D::SELECTORS)) === count($D::SELECTORS) && in_array('selector1', $D::SELECTORS, true), 'selectors');
});

test('maillog-grafiek: dagen in de tijdzone van de site, ook rond zomertijd', function () {
    $C = \NextBuzz\OcMonitor\Client\Smtp\Log\Chart::class;
    $tz = new \DateTimeZone('Europe/Amsterdam');
    // Zomertijd begint zondag 29 maart 2026 (die dag heeft 23 uur).
    [$since, $until] = $C::range(gmmktime(11, 0, 0, 3, 30, 2026), $tz, 3);
    check($since === gmmktime(23, 0, 0, 3, 27, 2026) && $until === gmmktime(22, 0, 0, 3, 30, 2026), 'bereik van lokale middernacht tot middernacht');
    [$s14, $u14] = $C::range(NOW, $tz);
    check(count($C::groupByDay([], $s14, $u14, $tz)) === 14, '14 dagen');
    $days = $C::groupByDay([
        ['start' => gmmktime(23, 0, 0, 3, 27, 2026), 'sent' => '2', 'failed' => '0', 'backup' => '0'],   // 28 mrt 00:00 CET
        ['start' => gmmktime(21, 45, 0, 3, 29, 2026), 'sent' => '3', 'failed' => '1', 'backup' => '1'],  // 29 mrt 23:45 CEST
        ['start' => gmmktime(22, 0, 0, 3, 29, 2026), 'sent' => '4', 'failed' => '0', 'backup' => '0'],   // 30 mrt 00:00 CEST
        ['start' => gmmktime(22, 0, 0, 3, 30, 2026), 'sent' => '9', 'failed' => '9', 'backup' => '9'],   // buiten bereik
    ], $since, $until, $tz);
    check($days === [
        ['date' => '2026-03-28', 'sent' => 2, 'failed' => 0, 'backup' => 0],
        ['date' => '2026-03-29', 'sent' => 3, 'failed' => 1, 'backup' => 1],
        ['date' => '2026-03-30', 'sent' => 4, 'failed' => 0, 'backup' => 0],
    ], 'optellen per lokale dag');
    check($C::shortLabel('2026-11-16') === 'ma 16' && $C::longLabel('2026-03-29') === 'zondag 29 maart', 'Nederlandse labels');
    check($C::niceMax(0) === 4 && $C::niceMax(4) === 4 && $C::niceMax(5) === 8 && $C::niceMax(37) === 40 && $C::niceMax(101) === 200 && $C::niceMax(1999) === 2000, 'nette y-as');
});

test('mailcijfers voor het panel: alleen dagen die de log nog compleet heeft', function () {
    $M = \NextBuzz\OcMonitor\Client\Smtp\Module::class;
    check($M::dailyWindow(false, 30) === 0, 'log uit: niets');
    check($M::dailyWindow(true, 7) === 6 && $M::dailyWindow(true, 30) === 29, 'oudste (deels opgeruimde) dag niet mee');
    check($M::dailyWindow(true, 365) === 92 && $M::dailyWindow(true, 0) === 92, 'hoogstens een kwartaal');
});

test('maillog-grafiek: SVG', function () {
    $C = \NextBuzz\OcMonitor\Client\Smtp\Log\Chart::class;
    $days = [];
    for ($i = 0; $i < 14; $i++) {
        $days[] = ['date' => gmdate('Y-m-d', gmmktime(12, 0, 0, 11, 4 + $i, 2026)), 'sent' => $i, 'failed' => $i % 3 === 0 ? 1 : 0, 'backup' => $i === 5 ? 2 : 0];
    }
    $svg = $C::svg($days, 'Titel <x>');
    check(strpos($svg, '<svg') === 0 && substr($svg, -6) === '</svg>' && strpos($svg, 'viewBox="0 0 960 200"') !== false && strpos($svg, 'width="100%"') !== false, 'responsief svg');
    check(substr_count($svg, '<title>') === 14 && substr_count($svg, 'role="img"') === 14, 'titel per staaf');
    check(strpos($svg, 'Titel &lt;x&gt;') !== false && strpos($svg, '<x>') === false, 'ge-escapet');
    check(strpos($svg, 'maandag 16 november: 12 verzonden, 1 mislukt') !== false, 'toegankelijk label');
    check(strpos($svg, 'maandag 9 november: 5 verzonden (waarvan 2 via backup), 0 mislukt') !== false, 'backup in label');
    check(strpos($svg, '#1F9D55') !== false && strpos($svg, '#E8A317') !== false && strpos($svg, '#C41E25') !== false, 'kleuren');
    check(strpos($svg, '>ma 16<') !== false && strpos($svg, '>wo 4<') !== false, 'daglabels');
    check(preg_match('/(height|width|y)="-/', $svg) === 0, 'geen negatieve maten');
    check($C::part(['sent' => 5, 'failed' => 1, 'backup' => 2], 'sent') === 3, 'verzonden zonder backup in de stapel');
    check(strpos($C::legend(), 'Via backup') !== false, 'legenda');
    $empty = $C::svg([['date' => '2026-11-17', 'sent' => 0, 'failed' => 0, 'backup' => 0]]);
    check(strpos($empty, 'fill="#1F9D55"') === false && strpos($empty, '0 verzonden, 0 mislukt') !== false, 'lege dag');
});

// ---- widget: status en historie

test('widget: status en historie worden streng genormaliseerd, oude gegevens blijven werken', function () {
    $W = \NextBuzz\OcMonitor\Client\Admin\DashboardWidget::class;
    $old = $W::normalize(['next' => '2026-11-16'], 'oc.nextbuzz.nl');
    check($old['reports'] === [] && $old['status']['ssl'] === ['ok' => null, 'expires' => null] && $old['status']['uptime']['ok'] === null, 'oude payload');
    check($W::statusLights($old, null, NOW) === [], 'geen gegevens: geen lampjes');

    $reports = [
        ['date' => '2026-11-17', 'label' => '<b>Onderhoud</b> november 2026', 'url' => 'https://oc.nextbuzz.nl/api/rapport/1/9?signature=x'],
        ['date' => '2026-08-17', 'label' => 'x', 'url' => 'http://oc.nextbuzz.nl/api/rapport/1/8'],
        ['date' => '2026-05-17', 'label' => 'x', 'url' => 'https://phish.example/api/rapport/1/7'],
        ['date' => '2026-02-30', 'label' => 'x', 'url' => 'https://oc.nextbuzz.nl/api/rapport/1/6'],
        ['date' => '2026-02-17', 'url' => 'https://OC.nextbuzz.nl/api/rapport/1/5'],
        'onzin',
    ];
    $n = $W::normalize(['status' => [
        'ssl' => ['ok' => 'ja', 'expires' => '2027-13-01'],
        'uptime' => ['ok' => true, 'percent' => 140, 'down_since' => 'gisteren'],
    ], 'reports' => $reports], 'oc.nextbuzz.nl');
    check($n['status']['ssl'] === ['ok' => null, 'expires' => null], 'alleen echte booleans en geldige datums');
    check($n['status']['uptime'] === ['ok' => true, 'percent' => 100.0, 'down_since' => null], 'percentage begrensd');
    check($W::normalize(['status' => ['uptime' => ['percent' => -3]]], null)['status']['uptime']['percent'] === 0.0, 'niet onder 0');
    check(count($n['reports']) === 2, 'http, andere host en ongeldige datum vallen weg');
    check($n['reports'][0] === ['date' => '2026-11-17', 'label' => 'Onderhoud november 2026', 'url' => 'https://oc.nextbuzz.nl/api/rapport/1/9?signature=x'], 'label zonder html');
    check($n['reports'][1]['label'] === 'Onderhoud 2026-02-17', 'standaardlabel');
    check($W::normalize(['reports' => $reports], null)['reports'] === [], 'niet gekoppeld: geen links');
    $many = array_fill(0, 20, ['date' => '2026-01-01', 'label' => 'x', 'url' => 'https://oc.nextbuzz.nl/r']);
    check(count($W::normalize(['reports' => $many], 'oc.nextbuzz.nl')['reports']) === 12, 'hoogstens 12');
    check($W::normalize(['reports' => 'x', 'status' => 'x'], 'oc.nextbuzz.nl')['reports'] === [], 'verkeerd type');
});

test('widget: statuslampjes met tekst en kleur', function () {
    $W = \NextBuzz\OcMonitor\Client\Admin\DashboardWidget::class;
    $tz = new \DateTimeZone('Europe/Amsterdam');
    $now = (new \DateTimeImmutable('2026-11-17 10:00:00', $tz))->getTimestamp();
    $lights = static function (array $status, ?array $mail = null) use ($W, $now, $tz): array {
        $out = [];
        foreach ($W::statusLights($W::normalize(['status' => $status], null), $mail, $now, $tz) as $l) {
            $out[] = $l['tone'] . ':' . $l['label'];
        }

        return $out;
    };
    check($lights(['ssl' => ['ok' => true, 'expires' => '2027-03-12'], 'uptime' => ['ok' => true, 'percent' => 99.98]], ['sent' => 40, 'failed' => 0, 'total' => 40])
        === ['ok:SSL geldig tot 12 mrt 2027', 'ok:Online · 99,98% (30 dagen)', 'ok:E-mail: alles verstuurd (7 dagen)'], 'alles goed');
    check($lights(['ssl' => ['ok' => true, 'expires' => '2026-11-26']]) === ['warn:SSL verloopt over 9 dagen'], 'bijna verlopen');
    check($lights(['ssl' => ['ok' => true, 'expires' => '2026-11-18']]) === ['warn:SSL verloopt morgen'], 'morgen');
    check($lights(['ssl' => ['ok' => false, 'expires' => '2027-03-12']]) === ['crit:SSL-probleem'], 'probleem');
    check($lights(['uptime' => ['ok' => true, 'percent' => 100]]) === ['ok:Online · 100% (30 dagen)'], '100%');
    check($lights(['uptime' => ['ok' => true, 'percent' => 98.5]]) === ['warn:Online · 98,50% (30 dagen)'], 'lage uptime oranje');
    check($lights(['uptime' => ['ok' => false, 'down_since' => '2026-11-17T08:05:00+00:00']]) === ['crit:Offline sinds 09:05'], 'vandaag offline, lokale tijd');
    check($lights(['uptime' => ['ok' => false, 'down_since' => '2026-11-15T22:30:00Z']]) === ['crit:Offline sinds 15 nov 2026 23:30'], 'eerder offline');
    check($lights(['uptime' => ['ok' => false]]) === ['crit:Offline'], 'zonder tijdstip');
    check($lights([], ['sent' => 37, 'failed' => 3, 'total' => 40]) === ['warn:E-mail: 3 mislukt (7 dagen)'], 'mislukte mail');
    check($lights([], ['sent' => 0, 'failed' => 2, 'total' => 2]) === ['crit:E-mail: 2 mislukt (7 dagen)'], 'alles mislukt');
    check($lights([], ['sent' => 0, 'failed' => 0, 'total' => 0]) === [], 'geen mail: geen lampje');
});

// ---- Systeem en support-export

/** Feiten zoals SystemStatus::collect() ze geeft, met nep-geheimen op plekken waar ze niet in de export mogen. */
function systemFacts(array $overrides = []): array
{
    return array_replace([
        'generated_at' => NOW, 'timezone' => 'Europe/Amsterdam', 'abspath' => '/var/www/klant/',
        'site_url' => 'https://klant.example.nl/wp?preview_token=URLSECRETQUERY', 'home_url' => 'https://klant.example.nl',
        'admin_email' => 'jan.jansen@klant.example.nl', 'locale' => 'nl_NL', 'environment' => 'production',
        'wp_version' => '6.8.3', 'wp_update' => null, 'php_version' => '8.3.10', 'db_server' => '5.5.5-10.11.6-MariaDB', 'server_software' => 'nginx',
        'wp_debug' => false, 'wp_debug_display' => false, 'timezone_string' => 'Europe/Amsterdam', 'gmt_offset' => 1.0, 'object_cache' => true,
        'memory_limit' => '256M', 'wp_memory_limit' => '40M', 'max_execution_time' => 60, 'upload_max_filesize' => '64M', 'post_max_size' => '64M',
        'disk_free' => 5 * 1073741824, 'uploads_writable' => true,
        'extensions' => ['sodium' => true, 'openssl' => true, 'curl' => true, 'mbstring' => true, 'zip' => true, 'intl' => true, 'image' => true, 'imap' => false],
        'cron' => ['disabled' => false, 'alternate' => false, 'events' => 10, 'overdue' => 0, 'oldest_overdue_seconds' => null, 'own' => ['nb_ocm_hourly' => NOW + 600, 'nb_ocm_twicedaily' => NOW + 9000]],
        'paired' => true, 'site_id' => SITE_ID, 'panel_host' => 'ocm.nextbuzz.nl', 'paired_at' => NOW - 86400, 'scopes' => ['read', 'events'],
        'last_panel_request' => NOW - 300, 'plugin_version' => '0.16.0',
        // Velden die de export nooit mag overnemen:
        'site_key' => 'SITEKEYSECRETb64AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'panel_url' => 'https://ocm.nextbuzz.nl/?k=PANELURLSECRET',
        'theme' => ['name' => 'Astra', 'version' => '4.8', 'parent' => null],
        'plugins' => [['file' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'version' => '9.3.1', 'active' => true], ['file' => 'hello.php', 'name' => 'Hello Dolly', 'version' => '1.7.2', 'active' => false]],
        'mu_plugins' => [['file' => 'host-cache.php', 'name' => 'Host Cache', 'version' => '1.0']],
        'tweaks' => ['disable_comments' => true, 'heartbeat' => 'slow', 'smtp_module' => true],
        'smtp' => ['enabled' => true, 'active' => true, 'reason' => null, 'host' => 'smtp.klant.example.nl', 'port' => 587, 'encryption' => 'tls',
            'from_email' => 'noreply@klant.example.nl', 'user' => 'SMTPUSERSECRET', 'pass' => 'SMTPPASSSECRET', 'conflicts' => [],
            'last_test' => ['ok' => false, 'time' => NOW - 60, 'error' => 'Login mislukt voor smtpuser@klant.example.nl met wachtwoord']],
        'alerts' => ['emails' => ['ALERTMAILSECRET@example.org'], 'webhooks' => ['https://hooks.slack.com/services/T000/B000/WEBHOOKTOKENSECRET', 'https://example.org/hook?token=QUERYTOKENSECRET'],
            'events' => ['failed' => true], 'throttle' => 15],
        'php_errors' => [[
            'type' => 'Fatal error', 'count' => 3, 'last_seen' => NOW - 3600, 'line' => 12, 'url' => '/afrekenen/?key=wc_order_ORDERKEYSECRET',
            'file' => '/var/www/klant/wp-content/plugins/x/x.php',
            // Nepsleutel in stukjes, zodat secret scanning (GitHub) hem niet voor een echte Stripe-sleutel aanziet.
            'message' => "Uncaught Exception: API-sleutel " . 'sk_' . 'live_' . str_repeat('4eC39HqLyj', 5) . " geweigerd voor klant@gmail.com in /var/www/klant/wp-content/plugins/x/x.php:12 https://api.example.com/v1?apikey=APIKEYQUERYSECRET" . str_repeat(' lang', 200),
        ]],
        'cookies' => 'wordpress_logged_in_COOKIESECRET',
    ], $overrides);
}

test('systeem: drempels voor schijf, panelcontact, PHP-EOL, cron en debug', function () {
    $S = \NextBuzz\OcMonitor\Client\Admin\SystemStatus::class;
    $find = static function (array $sections, string $label): array {
        foreach ($sections as $checks) {
            foreach ($checks as $c) {
                if ($c['label'] === $label) {
                    return $c;
                }
            }
        }
        throw new \RuntimeException('check ontbreekt: ' . $label);
    };
    $tone = static function (array $overrides, string $label) use ($S, $find): string {
        return $find($S::evaluate(systemFacts($overrides), NOW), $label)['tone'];
    };
    $healthy = $S::counts($S::evaluate(systemFacts(['php_errors' => []]), NOW));
    check($healthy['crit'] === 0 && $healthy['warn'] === 0, 'gezonde site zonder waarschuwingen');
    check($tone(['disk_free' => 150 * 1048576], 'Vrije schijfruimte') === 'crit', 'schijf < 200 MB');
    check($tone(['disk_free' => 500 * 1048576], 'Vrije schijfruimte') === 'warn', 'schijf < 1 GB');
    check($tone(['disk_free' => null], 'Vrije schijfruimte') === 'info', 'schijf onbekend');
    check($tone(['last_panel_request' => NOW - 30 * 3600], 'Laatste contact') === 'warn', 'panel > 24 uur');
    check($tone(['last_panel_request' => NOW - 80 * 3600], 'Laatste contact') === 'crit', 'panel > 72 uur');
    check($tone(['last_panel_request' => null], 'Laatste contact') === 'warn', 'gekoppeld zonder contact');
    check($tone(['php_version' => '8.0.30'], 'PHP') === 'crit', 'PHP 8.0 is EOL');
    check($tone(['php_version' => '7.2.1'], 'PHP') === 'crit', 'PHP < 7.4');
    check($tone(['php_version' => '8.2.20', 'generated_at' => NOW], 'PHP') === 'ok', 'PHP 8.2 nog ruim ondersteund op NOW');
    check($S::evaluate(systemFacts(['php_version' => '8.2.20']), strtotime('2026-11-17'))['WordPress en PHP'][1]['tone'] === 'warn', 'PHP 8.2 bijna EOL');
    check($tone(['cron' => ['overdue' => 2, 'oldest_overdue_seconds' => 7200, 'own' => []]], 'Achterstallige taken') === 'warn', 'cron > 1 uur');
    check($tone(['cron' => ['overdue' => 2, 'oldest_overdue_seconds' => 90000, 'own' => []]], 'Achterstallige taken') === 'crit', 'cron > 24 uur');
    check($tone(['cron' => ['own' => ['nb_ocm_hourly' => null]]], 'Taak elk uur') === 'warn', 'eigen taak niet ingepland');
    check($tone(['wp_debug' => true, 'wp_debug_display' => true], 'Debugmodus') === 'warn', 'debug op live site');
    check($tone(['wp_debug' => true, 'environment' => 'staging'], 'Debugmodus') === 'info', 'debug op staging');
    check($tone(['timezone_string' => '', 'gmt_offset' => 0], 'Tijdzone') === 'warn', 'tijdzone als UTC-offset');
    check($tone(['home_url' => 'http://klant.example.nl'], 'HTTPS') === 'crit', 'geen https');
    check($tone(['extensions' => ['sodium' => false]], 'sodium') === 'crit' && $tone(['extensions' => []], 'imap') === 'info', 'extensies');
    check($tone(['memory_limit' => '96M'], 'Geheugen') === 'warn' && $tone(['memory_limit' => '-1'], 'Geheugen') === 'ok', 'geheugen');
    check($tone(['db_server' => '5.7.44'], 'Database') === 'warn' && $find($S::evaluate(systemFacts(), NOW), 'Database')['value'] === 'MariaDB 10.11.6', 'database');
    check($tone(['php_errors' => [['last_seen' => NOW - 100], ['last_seen' => NOW - 90000]]], 'PHP-fouten (24 uur)') === 'warn' && $S::recentErrors([['last_seen' => NOW - 90000]], NOW) === 0, 'PHP-fouten 24 uur');
    check($S::bytes('1G') === 1073741824 && $S::bytes('512K') === 524288 && $S::duration(7300) === '2 uur' && $S::duration(3 * 86400) === '3 dagen', 'hulpfuncties');
});

test('support-export: bevat de feiten maar nooit geheimen, volledige adressen of paden', function () {
    $X = \NextBuzz\OcMonitor\Client\Admin\SupportExport::class;
    $text = $X::build(systemFacts());
    foreach (['WooCommerce 9.3.1', '[uit   ] Hello Dolly 1.7.2', 'Host Cache 1.0', 'Site-ID: ' . SITE_ID, 'Scopes: read, events', 'smtp.klant.example.nl:587', 'Webhooks: 2 (hooks.slack.com, example.org)',
        'E-mailontvangers: 1', 'Beheerdersmail: j***@klant.example.nl', 'Afzender: n***@klant.example.nl', 'wp-content/plugins/x/x.php:12', '(/afrekenen/)', 'MariaDB', '[OK] PHP: 8.3.10', 'disable_comments=aan'] as $needle) {
        check(strpos($text, $needle) !== false, 'ontbreekt: ' . $needle);
    }
    foreach (['SECRET', 'jan.jansen', 'smtpuser@', 'klant@gmail.com', 'sk_live_', '/var/www', 'apikey=', 'preview_token', 'wordpress_logged_in', 'ALERTMAIL', 'T000/B000'] as $secret) {
        check(stripos($text, $secret) === false, 'gelekt: ' . $secret);
    }
    check(strpos($text, 'k***@gmail.com') !== false, 'e-mail in foutmelding ingekort');
    $errorLines = array_values(preg_grep('/Fatal error/', explode("\n", $text)));
    check(count($errorLines) === 1 && mb_strlen($errorLines[0]) < 400, 'foutmelding ingekort');
    check($X::maskEmail('info@klant.nl') === 'i***@klant.nl' && $X::maskEmail('') === '-', 'maskEmail');
    check($X::scrub('zie https://x.nl/a?b=c#d en abcdefABCDEF0123456789abcdefABCDEF01') === 'zie https://x.nl/a?[weggelaten] en [weggelaten]', 'scrub');
    check($X::filename('https://Klant.example.nl', NOW) === 'support-klant.example.nl-' . gmdate('Ymd-Hi', NOW) . '.txt', 'bestandsnaam');
});

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL {$failure}\n");
}
printf("%d geslaagd, %d mislukt\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);
