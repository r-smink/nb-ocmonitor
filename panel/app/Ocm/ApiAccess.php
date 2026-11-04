<?php

namespace App\Ocm;

use App\Models\Setting;

/**
 * Aan/uit-schakelaar voor de eigen alleen-lezen API (/api/v1). Staat standaard uit: zolang hij
 * uit staat geeft elke /api/v1-aanvraag een 404, ook met een geldig token. De koppeling met de
 * client-plugin (/api/pair, /api/sites/..., de plugin-download) hangt hier niet van af.
 */
final class ApiAccess
{
    public const SETTING = 'api';

    public static function enabled(): bool
    {
        try {
            return (bool) (Setting::get(self::SETTING)['enabled'] ?? false);
        } catch (\Throwable) {
            return false; // tabel bestaat nog niet
        }
    }

    public static function set(bool $enabled): void
    {
        Setting::put(self::SETTING, ['enabled' => $enabled, 'changed_at' => now()->toIso8601String()]);
    }
}
