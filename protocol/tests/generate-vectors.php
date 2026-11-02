<?php

/**
 * Schrijft tests/vectors.json opnieuw. Alleen draaien bij een bewuste protocolwijziging:
 * de vectoren zijn het contract tussen panel en plugin.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use NextBuzz\OcMonitor\Protocol\Base64Url;
use NextBuzz\OcMonitor\Protocol\Canonical;
use NextBuzz\OcMonitor\Protocol\KeyPair;
use NextBuzz\OcMonitor\Protocol\LoginToken;
use NextBuzz\OcMonitor\Protocol\Pairing;
use NextBuzz\OcMonitor\Protocol\Signer;

$panel = KeyPair::fromSeed(VECTOR_PANEL_SEED);
$site = KeyPair::fromSeed(VECTOR_SITE_SEED);

$command = [
    'context' => Canonical::CTX_COMMAND,
    'method' => 'POST',
    'host' => VECTOR_HOST,
    'path' => '/nb-ocm/v1/cmd',
    'site_id' => VECTOR_SITE_ID,
    'timestamp' => VECTOR_TS,
    'nonce' => VECTOR_NONCE,
    'body' => VECTOR_COMMAND_BODY,
];
$command['canonical'] = Canonical::request($command['context'], 'POST', VECTOR_HOST, $command['path'], VECTOR_SITE_ID, $panel->keyId(), VECTOR_TS, VECTOR_NONCE, VECTOR_COMMAND_BODY);
$command['headers'] = (new Signer($panel))->signRequest($command['context'], 'POST', VECTOR_HOST, $command['path'], VECTOR_SITE_ID, VECTOR_COMMAND_BODY, VECTOR_TS, VECTOR_NONCE);

$event = [
    'context' => Canonical::CTX_SITE,
    'method' => 'POST',
    'host' => 'ocm.nextbuzz.nl',
    'path' => '/api/sites/site_01/events',
    'site_id' => VECTOR_SITE_ID,
    'timestamp' => VECTOR_TS,
    'nonce' => VECTOR_NONCE,
    'body' => VECTOR_EVENT_BODY,
];
$event['canonical'] = Canonical::request($event['context'], 'POST', $event['host'], $event['path'], VECTOR_SITE_ID, $site->keyId(), VECTOR_TS, VECTOR_NONCE, VECTOR_EVENT_BODY);
$event['headers'] = (new Signer($site))->signRequest($event['context'], 'POST', $event['host'], $event['path'], VECTOR_SITE_ID, VECTOR_EVENT_BODY, VECTOR_TS, VECTOR_NONCE);

$response = [
    'site_id' => VECTOR_SITE_ID,
    'request_nonce' => VECTOR_NONCE,
    'status' => 200,
    'timestamp' => VECTOR_TS + 1,
    'body' => VECTOR_RESPONSE_BODY,
];
$response['canonical'] = Canonical::response(VECTOR_SITE_ID, $site->keyId(), VECTOR_TS + 1, VECTOR_NONCE, 200, VECTOR_RESPONSE_BODY);
$response['headers'] = (new Signer($site))->signResponse(VECTOR_SITE_ID, VECTOR_NONCE, 200, VECTOR_RESPONSE_BODY, VECTOR_TS + 1);

$login = [
    'site_id' => VECTOR_SITE_ID,
    'host' => VECTOR_HOST,
    'wp_user' => 'nb-rick',
    'panel_user' => 'rick@nextbuzz.nl',
    'now' => VECTOR_TS,
    'nonce' => VECTOR_LOGIN_NONCE,
];
$login['token'] = LoginToken::issue($panel, VECTOR_SITE_ID, VECTOR_HOST, 'nb-rick', 'rick@nextbuzz.nl', VECTOR_TS, 60, VECTOR_LOGIN_NONCE);

$pairing = Pairing::proof(VECTOR_SITE_ID, $site, 'https://' . VECTOR_HOST, VECTOR_PAIRING_SECRET, VECTOR_TS);

$vectors = [
    'note' => 'Gegenereerd door tests/generate-vectors.php. Ed25519 is deterministisch: elke implementatie moet exact deze waarden opleveren.',
    'keys' => [
        'panel' => ['seed_hex' => bin2hex(VECTOR_PANEL_SEED), 'public_key' => $panel->publicKeyB64(), 'key_id' => $panel->keyId()],
        'site' => ['seed_hex' => bin2hex(VECTOR_SITE_SEED), 'public_key' => $site->publicKeyB64(), 'key_id' => $site->keyId()],
    ],
    'command_request' => $command,
    'site_event_request' => $event,
    'response' => $response,
    'login_token' => $login,
    'pairing_proof' => ['secret_b64' => Base64Url::encode(VECTOR_PAIRING_SECRET), 'proof' => $pairing],
];

file_put_contents(__DIR__ . '/vectors.json', json_encode($vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "vectors.json geschreven\n";
