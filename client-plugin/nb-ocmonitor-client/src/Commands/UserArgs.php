<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandError;

/**
 * Validatie van de argumenten van gebruikers-commands, zonder WordPress (voor de tests).
 */
final class UserArgs
{
    /** Rollen waarnaar een beheerder vanuit het panel teruggezet mag worden. Nooit naar administrator. */
    public const DEMOTE_ROLES = ['editor', 'author', 'contributor', 'subscriber'];

    public const TWO_FACTOR_MODES = ['off', 'admins', 'editors'];

    /** @param array<string, mixed> $args {user_id: int} */
    public static function userId(array $args): int
    {
        self::only($args, ['user_id']);
        $id = $args['user_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new CommandError('bad_request', 'user_id moet een positief getal zijn');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $args {user_id: int, role: string}
     * @return array{user_id: int, role: string}
     */
    public static function demote(array $args): array
    {
        self::only($args, ['user_id', 'role']);
        $role = $args['role'] ?? null;
        if (!is_string($role) || !in_array($role, self::DEMOTE_ROLES, true)) {
            throw new CommandError('bad_request', 'Rol moet een van ' . implode(', ', self::DEMOTE_ROLES) . ' zijn');
        }

        return ['user_id' => self::userId(['user_id' => $args['user_id'] ?? null]), 'role' => $role];
    }

    /** @param array<string, mixed> $args {mode: string} */
    public static function twoFactorMode(array $args): string
    {
        self::only($args, ['mode']);
        $mode = $args['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::TWO_FACTOR_MODES, true)) {
            throw new CommandError('bad_request', 'mode moet een van ' . implode(', ', self::TWO_FACTOR_MODES) . ' zijn');
        }

        return $mode;
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
