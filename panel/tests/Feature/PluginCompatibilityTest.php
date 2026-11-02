<?php

namespace Tests\Feature;

use App\Models\SiteJob;
use App\Ocm\JobQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Endpoint;
use NextBuzz\OcMonitor\Client\FailureLog;
use NextBuzz\OcMonitor\Client\SiteConfig;
use NextBuzz\OcMonitor\Protocol\InMemoryNonceStore;
use Tests\TestCase;

/**
 * De signer praat hier met de échte Endpoint-klasse uit de client-plugin (zonder WordPress).
 * Breekt deze test, dan zijn panel en plugin niet meer compatibel.
 */
class PluginCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        spl_autoload_register(static function (string $class): void {
            $prefix = 'NextBuzz\\OcMonitor\\Client\\';
            if (str_starts_with($class, $prefix)) {
                $file = __DIR__ . '/../../../client-plugin/nb-ocmonitor-client/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });
    }

    public function test_signer_and_real_plugin_endpoint_understand_each_other(): void
    {
        $site = $this->makePairedSite();
        $failures = new class implements FailureLog {
            public array $reasons = [];

            public function record(string $reason, string $ip, int $now): void
            {
                $this->reasons[] = $reason;
            }
        };
        $commands = new CommandRegistry();
        $commands->add('site.info', 'read', fn (array $args, CommandContext $ctx) => [
            'site_id' => $ctx->config->siteId, 'wp_version' => '6.9.1', 'php_version' => '8.2.0', 'plugin_version' => '0.1.0', 'last_event_seq' => 0,
        ]);
        $config = new SiteConfig($site->site_id, $site->host, 'https://ocm.test', [$this->panelKeys->publicKey()], $this->siteKeys, ['read', 'events']);
        $endpoint = new Endpoint($config, new InMemoryNonceStore(), $commands, $failures);

        Http::fake(function (HttpRequest $request) use ($endpoint) {
            $result = $endpoint->handle('POST', $request->headers(), $request->body(), '127.0.0.1', time());
            if ($result->notFound) {
                return Http::response('{"code":"rest_no_route"}', 404);
            }

            return Http::response($result->body, $result->status, $result->headers);
        });

        JobQueue::enqueue($site, 'site.info');
        $this->artisan('ocm:signer --once')->assertSuccessful();

        $this->assertSame([], $failures->reasons, 'plugin wees het verzoek af: ' . implode(',', $failures->reasons));
        $this->assertSame('done', SiteJob::query()->firstOrFail()->status);
        $this->assertSame('6.9.1', $site->fresh()->wp_version);
    }
}
