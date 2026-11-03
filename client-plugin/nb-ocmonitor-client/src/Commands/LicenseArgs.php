<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandError;

/** Validatie van license.activate, zonder WordPress (voor de tests). */
final class LicenseArgs
{
    /** WordPress-opties die nooit via een licentie gezet mogen worden, ook al zou de naam passen. */
    private const PROTECTED_OPTIONS = ['siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'active_plugins', 'template', 'stylesheet'];

    /**
     * @param array<string, mixed> $args
     * @return array{adapter: string, key: string, option: ?string}
     */
    public static function activate(array $args): array
    {
        if (array_diff(array_keys($args), ['adapter', 'key', 'option']) !== []) {
            throw new CommandError('bad_request', 'Alleen adapter, key en option zijn toegestaan');
        }
        $adapter = $args['adapter'] ?? null;
        if (!is_string($adapter) || !in_array($adapter, LicenseCommands::ADAPTERS, true)) {
            throw new CommandError('bad_request', 'Onbekende activeringsmethode');
        }
        $key = $args['key'] ?? null;
        if (!is_string($key) || preg_match('/^[A-Za-z0-9._\-=+\/]{4,200}$/D', $key) !== 1) {
            throw new CommandError('bad_request', 'Ongeldige licentiesleutel');
        }
        $option = null;
        if ($adapter === 'option') {
            $option = $args['option'] ?? null;
            if (!is_string($option) || preg_match('/^[a-z0-9_-]{0,80}licen[cs]e[a-z0-9_-]{0,80}$/D', $option) !== 1
                || in_array($option, self::PROTECTED_OPTIONS, true) || strpos($option, 'nb_ocm') === 0) {
                throw new CommandError('bad_request', 'De optienaam moet "license" of "licence" bevatten');
            }
        } elseif (isset($args['option'])) {
            throw new CommandError('bad_request', 'option hoort alleen bij de methode option');
        }

        return ['adapter' => $adapter, 'key' => $key, 'option' => $option];
    }
}
