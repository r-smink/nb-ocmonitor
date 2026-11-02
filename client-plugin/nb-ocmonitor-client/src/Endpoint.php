<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\NonceStore;
use NextBuzz\OcMonitor\Protocol\Signer;
use NextBuzz\OcMonitor\Protocol\StaticKeyResolver;
use NextBuzz\OcMonitor\Protocol\VerificationFailed;
use NextBuzz\OcMonitor\Protocol\Verifier;

/**
 * Verwerkt één command-verzoek van het panel. Bewust zonder WordPress-afhankelijkheden,
 * zodat de beveiligingskritische flow los te testen is (tests/run.php).
 *
 * Volgorde: grootte -> handtekening/timestamp/nonce (Verifier) -> body-schema -> expires_at
 * -> allowlist -> scope -> uitvoeren. Alles vóór een geldige handtekening levert een
 * generieke 404; daarna gaan fouten ondertekend terug naar het panel.
 */
final class Endpoint
{
    public const ROUTE = '/nb-ocm/v1/cmd';
    public const MAX_BODY_BYTES = 262144;
    public const MAX_JOB_LIFETIME = 3600;

    private ?SiteConfig $config;
    private NonceStore $nonces;
    private CommandRegistry $commands;
    private FailureLog $failures;
    /** @var callable(?string): void wordt aangeroepen met de job-id rond de uitvoering (voor event-attributie) */
    private $jobScope;

    /**
     * @param callable(?string): void|null $jobScope
     */
    public function __construct(?SiteConfig $config, NonceStore $nonces, CommandRegistry $commands, FailureLog $failures, ?callable $jobScope = null)
    {
        $this->config = $config;
        $this->nonces = $nonces;
        $this->commands = $commands;
        $this->failures = $failures;
        $this->jobScope = $jobScope ?? static function (?string $jobId): void {
        };
    }

    /**
     * @param array<string, string|string[]> $headers
     */
    public function handle(string $method, array $headers, string $body, string $remoteIp, int $now): EndpointResult
    {
        $config = $this->config;
        if ($config === null) {
            return EndpointResult::notFound();
        }

        try {
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw new VerificationFailed(VerificationFailed::MALFORMED, 'body too large');
            }
            $verifier = new Verifier(new StaticKeyResolver($config->panelPublicKeys), $this->nonces);
            $verified = $verifier->verifyRequest(Canonical::CTX_COMMAND, $method, $config->host, self::ROUTE, $config->siteId, $headers, $body, $now);
        } catch (VerificationFailed $e) {
            $this->failures->record($e->reason(), $remoteIp, $now);
            return EndpointResult::notFound();
        }

        // Vanaf hier is het verzoek authentiek: antwoorden gaan ondertekend terug.
        $jobId = '';
        try {
            $request = $this->parseBody($body, $now);
            $jobId = $request['job_id'];
            $command = $request['command'];

            if (!$this->commands->has($command)) {
                throw new CommandError('unknown_command', 'Onbekend command: ' . $command);
            }
            if (!$config->hasScope($this->commands->scopeOf($command))) {
                throw new CommandError('scope_denied', 'Command valt buiten de scopes van deze site', 403);
            }

            ($this->jobScope)($jobId);
            try {
                $result = $this->commands->run($command, $request['args'], new CommandContext($jobId, $now, $config));
            } finally {
                ($this->jobScope)(null);
            }

            return $this->respond($config, $verified->nonce, 200, ['ok' => true, 'job_id' => $jobId, 'result' => $result], $now);
        } catch (CommandError $e) {
            return $this->respond($config, $verified->nonce, $e->status(), [
                'ok' => false,
                'job_id' => $jobId,
                'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
            ], $now);
        } catch (\Throwable $e) {
            return $this->respond($config, $verified->nonce, 500, [
                'ok' => false,
                'job_id' => $jobId,
                'error' => ['code' => 'internal', 'message' => substr(get_class($e) . ': ' . $e->getMessage(), 0, 500)],
            ], $now);
        }
    }

    /**
     * @return array{command: string, args: array<string, mixed>, job_id: string, expires_at: int}
     */
    private function parseBody(string $body, int $now): array
    {
        $data = json_decode($body, true, 16);
        if (!is_array($data)) {
            throw new CommandError('bad_request', 'Body is geen geldig JSON-object');
        }
        $unknown = array_diff(array_keys($data), ['command', 'args', 'job_id', 'expires_at']);
        if ($unknown !== []) {
            throw new CommandError('bad_request', 'Onbekende velden: ' . implode(', ', $unknown));
        }
        if (!isset($data['command']) || !is_string($data['command']) || preg_match('/^[a-z]+(\.[a-z_]+)+$/D', $data['command']) !== 1) {
            throw new CommandError('bad_request', 'Ongeldig command');
        }
        if (!isset($data['job_id']) || !is_string($data['job_id']) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $data['job_id']) !== 1) {
            throw new CommandError('bad_request', 'Ongeldige job_id');
        }
        if (!isset($data['expires_at']) || !is_int($data['expires_at'])) {
            throw new CommandError('bad_request', 'Ongeldige expires_at');
        }
        if ($data['expires_at'] < $now || $data['expires_at'] > $now + self::MAX_JOB_LIFETIME) {
            throw new CommandError('expired', 'Job is verlopen of te lang geldig');
        }
        $args = $data['args'] ?? [];
        if (!is_array($args) || ($args !== [] && array_keys($args) === range(0, count($args) - 1))) {
            throw new CommandError('bad_request', 'args moet een object zijn');
        }

        return ['command' => $data['command'], 'args' => $args, 'job_id' => $data['job_id'], 'expires_at' => $data['expires_at']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(SiteConfig $config, string $requestNonce, int $status, array $payload, int $now): EndpointResult
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $headers = (new Signer($config->siteKeys))->signResponse($config->siteId, $requestNonce, $status, $body, $now);
        $headers['content-type'] = 'application/json; charset=utf-8';
        $headers['cache-control'] = 'no-store';

        return EndpointResult::signed($status, $headers, $body);
    }
}
