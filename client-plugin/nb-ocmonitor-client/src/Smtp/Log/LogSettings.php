<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Log;

/** Instellingen van de e-maillog in de optie nb_ocm_smtp_log. */
final class LogSettings
{
    public const OPTION = 'nb_ocm_smtp_log';
    /** dagen => label; 0 = altijd bewaren */
    public const RETENTIONS = [7 => '7 dagen', 30 => '30 dagen', 90 => '90 dagen', 180 => '180 dagen', 365 => '1 jaar', 0 => 'Altijd bewaren'];

    /** @return array{enabled: bool, log_content: bool, retention_days: int} */
    public static function defaults(): array
    {
        return ['enabled' => true, 'log_content' => false, 'retention_days' => 30];
    }

    /**
     * @param mixed $value
     * @return array{enabled: bool, log_content: bool, retention_days: int}
     */
    public static function normalize($value): array
    {
        $value = is_array($value) ? $value : [];
        $d = self::defaults();
        $days = is_numeric($value['retention_days'] ?? null) ? (int) $value['retention_days'] : $d['retention_days'];

        return [
            'enabled' => (bool) ($value['enabled'] ?? $d['enabled']),
            'log_content' => (bool) ($value['log_content'] ?? $d['log_content']),
            'retention_days' => array_key_exists($days, self::RETENTIONS) ? $days : $d['retention_days'],
        ];
    }

    /** @return array{enabled: bool, log_content: bool, retention_days: int} */
    public static function get(): array
    {
        return self::normalize(get_option(self::OPTION));
    }

    /**
     * Opslaan vanuit het formulier (niet aangevinkte schakelaars ontbreken in de POST).
     *
     * @param array<string, mixed> $input
     * @return array{enabled: bool, log_content: bool, retention_days: int}
     */
    public static function fromForm(array $input): array
    {
        return self::normalize([
            'enabled' => !empty($input['enabled']),
            'log_content' => !empty($input['log_content']),
            'retention_days' => $input['retention_days'] ?? null,
        ]);
    }

    /** @param array{enabled: bool, log_content: bool, retention_days: int} $settings */
    public static function save(array $settings): void
    {
        update_option(self::OPTION, self::normalize($settings), true);
    }

    /** Alles van vóór dit tijdstip mag weg; null = altijd bewaren. */
    public static function cutoff(int $now, int $days): ?int
    {
        return $days > 0 ? $now - $days * 86400 : null;
    }
}
