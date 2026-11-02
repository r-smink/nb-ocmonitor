<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client;

/**
 * Dunne WordPress-laag rond Endpoint. Een niet-geverifieerd verzoek krijgt exact dezelfde 404 als
 * een route die niet bestaat, zodat van buitenaf niets over de plugin te leren valt.
 */
final class RestController
{
    /** @var callable(): Endpoint */
    private $endpointFactory;

    /**
     * @param callable(): Endpoint $endpointFactory
     */
    public function __construct(callable $endpointFactory)
    {
        $this->endpointFactory = $endpointFactory;
    }

    public function register(): void
    {
        register_rest_route('nb-ocm/v1', '/cmd', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'authenticatedBySignature'],
            'show_in_index' => false,
        ]);
        add_filter('rest_pre_serve_request', [$this, 'serveRaw'], 10, 2);
    }

    /**
     * Authenticatie gebeurt in handle() via de Ed25519-handtekening, niet via WP-gebruikers:
     * het panel heeft geen WordPress-sessie.
     */
    public function authenticatedBySignature(): bool
    {
        return true;
    }

    /**
     * @param \WP_REST_Request $request
     * @return RawResponse|\WP_Error
     */
    public function handle($request)
    {
        $result = ($this->endpointFactory)()->handle(
            'POST',
            $request->get_headers(),
            (string) $request->get_body(),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            time()
        );

        if ($result->notFound) {
            // Identiek aan WordPress' eigen antwoord voor een onbekende route.
            return new \WP_Error('rest_no_route', __('No route was found matching the URL and request method.'), ['status' => 404]);
        }

        $response = new RawResponse($result->body, $result->status);
        foreach ($result->headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * De handtekening dekt de exacte body-bytes, dus WordPress mag de body niet zelf opnieuw
     * als JSON coderen. Headers zijn op dit moment al verstuurd.
     *
     * @param mixed $served
     * @param mixed $result
     * @return mixed
     */
    public function serveRaw($served, $result)
    {
        if ($result instanceof RawResponse) {
            echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- ondertekende JSON
            return true;
        }

        return $served;
    }
}
