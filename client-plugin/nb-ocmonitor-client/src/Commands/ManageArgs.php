<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandError;

/**
 * Validatie van plugin-beheer en onderhoudsmodus, zonder WordPress (voor de tests).
 */
final class ManageArgs
{
    /** Deze plugin zelf: nooit deactiveren of verwijderen vanuit het panel (dan is de koppeling weg). */
    public const SELF_SLUG = 'nb-ocmonitor-client';

    public const MAX_MESSAGE = 500;

    /** Onderhoudsmodus hoogstens zo lang; daarna gaat hij vanzelf uit. */
    public const MAX_MAINTENANCE_SECONDS = 7 * 86400;

    /**
     * Installeren: alleen een slug van wordpress.org. Het pakket bepaalt de site zelf via de
     * plugin-API van wordpress.org; het panel kan geen eigen code laten installeren.
     *
     * @param array<string, mixed> $args {slug: string, activate?: bool}
     * @return array{slug: string, activate: bool}
     */
    public static function install(array $args): array
    {
        self::only($args, ['slug', 'activate']);
        $slug = $args['slug'] ?? null;
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug) !== 1) {
            throw new CommandError('bad_request', 'Ongeldige slug (alleen kleine letters, cijfers en streepjes)');
        }
        if ($slug === self::SELF_SLUG) {
            throw new CommandError('bad_request', 'Deze plugin kan zichzelf niet installeren');
        }
        $activate = $args['activate'] ?? false;
        if (!is_bool($activate)) {
            throw new CommandError('bad_request', 'activate moet true of false zijn');
        }

        return ['slug' => $slug, 'activate' => $activate];
    }

    /**
     * Activeren, deactiveren of verwijderen: pluginbestanden zoals bij updates. Deze plugin zelf
     * mag niet gedeactiveerd of verwijderd worden.
     *
     * @param array<string, mixed> $args {plugins: string[]}
     * @return string[]
     */
    public static function plugins(array $args, bool $allowSelf = false): array
    {
        $files = UpdateArgs::plugins($args);
        if (!$allowSelf) {
            foreach ($files as $file) {
                if (strpos($file, self::SELF_SLUG . '/') === 0) {
                    throw new CommandError('forbidden', 'NB OC Monitor zelf kan niet vanuit het panel worden uitgezet of verwijderd');
                }
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $args {enabled: bool, message?: string, until?: int}
     * @return array{enabled: bool, message: string, until: ?int}
     */
    public static function maintenance(array $args, int $now): array
    {
        self::only($args, ['enabled', 'message', 'until']);
        $enabled = $args['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw new CommandError('bad_request', 'enabled moet true of false zijn');
        }
        $message = $args['message'] ?? '';
        if (!is_string($message) || mb_strlen($message) > self::MAX_MESSAGE) {
            throw new CommandError('bad_request', 'Bericht is te lang (maximaal ' . self::MAX_MESSAGE . ' tekens)');
        }
        $until = $args['until'] ?? null;
        if ($until !== null && (!is_int($until) || $until <= $now)) {
            throw new CommandError('bad_request', 'until moet een tijdstip in de toekomst zijn');
        }
        if ($enabled) {
            $until = min($until ?? $now + self::MAX_MAINTENANCE_SECONDS, $now + self::MAX_MAINTENANCE_SECONDS);
        }

        return ['enabled' => $enabled, 'message' => trim($message), 'until' => $enabled ? $until : null];
    }

    /**
     * @param array<string, mixed> $args
     * @param string[] $allowed
     */
    private static function only(array $args, array $allowed): void
    {
        if (array_diff(array_keys($args), $allowed) !== []) {
            throw new CommandError('bad_request', 'Alleen ' . implode(', ', $allowed) . ' zijn toegestaan');
        }
    }
}
