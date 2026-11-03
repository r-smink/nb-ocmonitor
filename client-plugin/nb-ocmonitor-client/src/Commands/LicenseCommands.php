<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Licenties van premium plugins (scope "licenses").
 *
 * Activeren kan alleen via vaste methodes in deze plugin, nooit via willekeurige code:
 * - elementor_pro: dezelfde stappen als `wp elementor-pro license activate`;
 * - acf_pro: acf_pro_activate_license();
 * - option: de sleutel in een optie zetten, alleen als de naam "license"/"licence" bevat en geen
 *   WordPress-optie is.
 * Voor de status leest de plugin wat de premium plugin zelf zegt (null = onbekend).
 */
final class LicenseCommands
{
    public const ADAPTERS = ['elementor_pro', 'acf_pro', 'option'];

    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('license.activate', 'licenses', [$this, 'activate']);
    }

    /**
     * Status per bekende premium plugin, voor de inventaris.
     *
     * @return array<string, array{active: ?bool, detail: ?string}>
     */
    public static function status(): array
    {
        $out = [];
        if (class_exists('\ElementorPro\License\API') && method_exists('\ElementorPro\License\API', 'is_license_active')) {
            $out['elementor_pro'] = ['active' => (bool) \ElementorPro\License\API::is_license_active(), 'detail' => null];
        }
        if (function_exists('acf_pro_is_license_active')) {
            $out['acf_pro'] = ['active' => (bool) acf_pro_is_license_active(), 'detail' => null];
        }
        if (class_exists('\RankMath\Admin\Admin_Helper') && method_exists('\RankMath\Admin\Admin_Helper', 'is_site_connected') && defined('RANK_MATH_PRO_VERSION')) {
            $out['rank_math_pro'] = ['active' => (bool) \RankMath\Admin\Admin_Helper::is_site_connected(), 'detail' => 'Rank Math gebruikt een accountkoppeling, geen sleutel'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $args {adapter: string, key: string, option?: string}
     * @return array<string, mixed>
     */
    public function activate(array $args, CommandContext $context): array
    {
        $a = LicenseArgs::activate($args);
        switch ($a['adapter']) {
            case 'elementor_pro':
                if (!class_exists('\ElementorPro\License\API') || !class_exists('\ElementorPro\License\Admin')) {
                    throw new CommandError('not_installed', 'Elementor Pro is niet actief op deze site', 409);
                }
                $response = \ElementorPro\License\API::activate_license($a['key']);
                if (is_wp_error($response)) {
                    throw new CommandError('license_failed', 'Elementor: ' . $response->get_error_message(), 422);
                }
                if (!is_array($response) || ($response['license'] ?? null) !== \ElementorPro\License\API::STATUS_VALID) {
                    $messages = ['no_activations_left' => 'geen activaties meer over', 'expired' => 'licentie verlopen', 'missing' => 'onbekende sleutel', 'revoked' => 'licentie ingetrokken', 'key_mismatch' => 'sleutel hoort niet bij dit domein'];
                    $code = is_array($response) ? (string) ($response['error'] ?? 'unknown') : 'unknown';
                    throw new CommandError('license_failed', 'Elementor: ' . ($messages[$code] ?? $code), 422);
                }
                \ElementorPro\License\Admin::set_license_key($a['key']);
                if (method_exists('\ElementorPro\License\API', 'set_license_data')) {
                    \ElementorPro\License\API::set_license_data($response);
                }
                break;

            case 'acf_pro':
                if (!function_exists('acf_pro_activate_license')) {
                    throw new CommandError('not_installed', 'ACF Pro is niet actief op deze site (of te oud)', 409);
                }
                $result = acf_pro_activate_license($a['key'], true);
                if (is_wp_error($result) || $result === false) {
                    throw new CommandError('license_failed', 'ACF: ' . (is_wp_error($result) ? $result->get_error_message() : 'activeren mislukt'), 422);
                }
                break;

            case 'option':
                update_option((string) $a['option'], $a['key'], false);
                break;
        }

        $status = self::status();
        $active = $status[$a['adapter']]['active'] ?? null;
        $this->recorder->record('license.activated', $a['adapter'] === 'option' ? 'option:' . $a['option'] : $a['adapter'], null, null, $active === false ? 'failed' : 'success');

        return ['adapter' => $a['adapter'], 'active' => $active];
    }
}
