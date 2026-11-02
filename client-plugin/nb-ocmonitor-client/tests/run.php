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
use NextBuzz\OcMonitor\Client\Endpoint;
use NextBuzz\OcMonitor\Client\EndpointResult;
use NextBuzz\OcMonitor\Client\FailureLog;
use NextBuzz\OcMonitor\Client\SiteConfig;
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

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL {$failure}\n");
}
printf("%d geslaagd, %d mislukt\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);
