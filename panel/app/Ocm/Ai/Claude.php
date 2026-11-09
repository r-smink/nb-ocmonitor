<?php

namespace App\Ocm\Ai;

use Anthropic\Client;

/**
 * Korte opdrachten aan Claude met een JSON-antwoord volgens een schema. Zonder API-sleutel of als
 * de functie uit staat gebeurt er niets; bij een fout of weigering komt er null terug en valt de
 * aanroeper terug op de eigen tekst. Alleen openbare of geanonimiseerde gegevens meesturen.
 */
final class Claude
{
    /** @var (\Closure(string, string, array<string, mixed>): ?array<string, mixed>)|null */
    private static ?\Closure $fake = null;

    /** Voor tests: vervangt de API-aanroep. null = weer de echte API. */
    public static function fake(?callable $handler): void
    {
        self::$fake = $handler !== null ? \Closure::fromCallable($handler) : null;
    }

    /** @param string $feature changelog|report */
    public static function enabled(string $feature): bool
    {
        return (self::$fake !== null || (string) config('ocm.ai.api_key') !== '') && (bool) config('ocm.ai.' . $feature);
    }

    public static function model(): string
    {
        return (string) config('ocm.ai.model', 'claude-opus-5-5');
    }

    /**
     * @param array<string, mixed> $schema JSON-schema van het antwoord (object, additionalProperties false)
     * @return array<string, mixed>|null
     */
    public static function json(string $system, string $prompt, array $schema, int $maxTokens = 4000): ?array
    {
        if (self::$fake !== null) {
            return (self::$fake)($system, $prompt, $schema);
        }

        try {
            $client = new Client(apiKey: (string) config('ocm.ai.api_key'), requestOptions: ['timeout' => 90.0, 'maxRetries' => 2]);
            $message = $client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: self::model(),
                fallbacks: 'default',
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                system: $system,
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($message->stopReason !== 'end_turn') {
            return null; // refusal of max_tokens: liever de eigen tekst dan een half antwoord
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }
}
