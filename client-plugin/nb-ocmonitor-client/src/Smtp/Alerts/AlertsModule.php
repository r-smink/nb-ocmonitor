<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp\Alerts;

use NextBuzz\OcMonitor\Client\Admin\Ui;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Meldingen bij mislukte mail. Luistert naar nb_ocm_smtp_mail_result (docs/smtp-module.md §3),
 * beslist via AlertState wat er gemeld wordt en stuurt dat:
 *  - als event naar het panel (direct, via de EventRecorder),
 *  - naar e-mail en webhooks (later, via een eenmalige cron-taak, zodat de mail zelf niet wacht),
 *  - als waarschuwing in WordPress (ProblemNotice).
 * Wordt aangeroepen vanuit Smtp\Module::register() als de module aan staat.
 */
final class AlertsModule
{
    public const DISPATCH_HOOK = 'nb_ocm_smtp_alert_dispatch';
    public const FLUSH_HOOK = 'nb_ocm_smtp_alert_flush';

    private EventRecorder $recorder;
    private bool $active = false;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    /** @param bool $active neemt de SMTP-module de verzending nu over (Module::state()['active']) */
    public function register(bool $active): void
    {
        $this->active = $active;
        add_action('nb_ocm_smtp_mail_result', [$this, 'onResult'], 10, 1);
        add_action(self::DISPATCH_HOOK, [$this, 'dispatch'], 10, 1);
        add_action(self::FLUSH_HOOK, [$this, 'flush'], 10, 1);
        ProblemNotice::register();
        if (is_admin()) {
            add_action('admin_post_nb_ocm_smtp_alerts_save', [$this, 'handleSave']);
            add_action('admin_post_nb_ocm_smtp_alerts_test', [$this, 'handleTest']);
        }
    }

    /** @param mixed $result */
    public function onResult($result): void
    {
        if (!is_array($result) || !empty($result['is_test'])) {
            return;
        }
        try {
            ProblemNotice::track($result);
            $settings = AlertSettings::get();
            $state = get_option(AlertState::OPTION);
            $out = AlertState::apply(is_array($state) ? $state : [], $result, time(), $settings['throttle'], $settings['events']);
            $this->commit($out, $settings);
        } catch (\Throwable $e) {
            // Een melding mag het versturen van mail nooit breken.
        }
    }

    /**
     * Cron: einde van een venster, samenvatting van de ingehouden meldingen.
     *
     * @param mixed $sig
     */
    public function flush($sig): void
    {
        $settings = AlertSettings::get();
        $state = get_option(AlertState::OPTION);
        $out = AlertState::flush(is_array($state) ? $state : [], (string) $sig, time(), $settings['throttle'], $settings['events']);
        $this->commit($out, $settings);
    }

    /**
     * @param array{state: array<string, mixed>, emit: list<array<string, mixed>>, schedule: list<array{sig: string, at: int}>} $out
     * @param array<string, mixed> $settings
     */
    private function commit(array $out, array $settings): void
    {
        update_option(AlertState::OPTION, $out['state'], false);
        foreach ($out['schedule'] as $item) {
            if (!wp_next_scheduled(self::FLUSH_HOOK, [$item['sig']])) {
                wp_schedule_single_event($item['at'] + 5, self::FLUSH_HOOK, [$item['sig']]);
            }
        }
        $hasChannels = $settings['emails'] !== [] || $settings['webhooks'] !== [];
        foreach ($out['emit'] as $emit) {
            if (!empty($emit['panel'])) {
                $this->recordEvent($emit);
            }
            if (!empty($emit['notify']) && $hasChannels) {
                // Uniek id: WordPress weigert een identieke eenmalige taak binnen 10 minuten.
                $job = ['id' => uniqid('', true), 'emit' => ['result' => AlertState::slim((array) $emit['result'])] + $emit];
                wp_schedule_single_event(time(), self::DISPATCH_HOOK, [$job]);
            }
        }
    }

    /** @param array<string, mixed> $emit */
    private function recordEvent(array $emit): void
    {
        $r = AlertState::slim((array) $emit['result']);
        $data = [
            'subject' => $r['subject'],
            'error' => $r['error'],
            'diagnose_code' => $r['diagnose_code'],
            'initiator' => $r['initiator'],
            'log_id' => $r['log_id'],
            'suppressed' => (int) $emit['suppressed'],
        ];
        if ($emit['type'] === 'recovered') {
            $data['failures'] = (int) ($emit['failures'] ?? 0);
        }
        if ($r['backup_error'] !== '') {
            $data['backup_error'] = $r['backup_error'];
        }
        $this->recorder->record(
            'smtp.' . $emit['type'],
            (string) ($r['to'][0] ?? ''),
            null,
            null,
            $emit['type'] === 'failed' ? 'failed' : 'success',
            $data
        );
    }

    /**
     * Cron: de melding echt versturen.
     *
     * @param mixed $job
     */
    public function dispatch($job): void
    {
        if (!is_array($job) || !is_array($job['emit'] ?? null)) {
            return;
        }
        $emit = $job['emit'];
        Notifier::deliver($emit, AlertSettings::get(), self::bypassFor($emit, $this->active));
    }

    /**
     * Via de standaard mail() als juist de SMTP-verbinding van deze module faalde; anders zou de
     * melding over een kapotte verbinding via diezelfde verbinding gaan.
     *
     * @param array<string, mixed> $emit
     */
    public static function bypassFor(array $emit, bool $active): bool
    {
        if (!$active || !in_array($emit['type'], ['failed', 'backup_used', 'test'], true)) {
            return false;
        }

        return in_array((string) ($emit['result']['connection'] ?? ''), ['primary', 'backup'], true);
    }

    public function handleSave(): void
    {
        Ui::guard('nb_ocm_smtp_alerts_save');
        $post = wp_unslash($_POST);
        $input = is_array($post['alerts'] ?? null) ? $post['alerts'] : [];
        $saved = AlertSettings::save($input);
        $invalid = $saved['invalid'];
        Ui::redirect('meldingen', $invalid === [] ? 'success' : 'error', $invalid === []
            ? 'Instellingen voor meldingen opgeslagen.'
            : 'Opgeslagen, maar dit is weggelaten (ongeldig, geen https of meer dan 3): ' . implode(', ', $invalid));
    }

    /** Testmelding: direct naar alle kanalen, zonder throttling, met het resultaat per kanaal. */
    public function handleTest(): void
    {
        Ui::guard('nb_ocm_smtp_alerts_test');
        $settings = AlertSettings::get();
        if ($settings['emails'] === [] && $settings['webhooks'] === []) {
            Ui::redirect('meldingen', 'error', 'Vul eerst een e-mailadres of webhook in en sla op.');
        }
        $emit = self::testEmit(time(), (string) wp_get_current_user()->user_email);
        $results = Notifier::deliver($emit, $settings, self::bypassFor($emit, $this->active));
        set_transient(self::testKey(), $results, 15 * MINUTE_IN_SECONDS);
        $failed = count(array_filter($results, static function (array $r): bool {
            return !$r['ok'];
        }));
        Ui::redirect('meldingen', $failed === 0 ? 'success' : 'error', $failed === 0
            ? 'Testmelding verstuurd naar alle kanalen. Controleer of hij aankomt (ook in de spammap).'
            : sprintf('De testmelding is niet overal aangekomen (%d van %d mislukt). Zie het resultaat hieronder.', $failed, count($results)));
    }

    /** @return array<string, mixed> een nagebootste mislukte mail voor de testmelding */
    public static function testEmit(int $now, string $to): array
    {
        return [
            'type' => 'test',
            'result' => [
                'log_id' => null,
                'status' => 'failed',
                'connection' => 'primary',
                'to' => [$to !== '' ? $to : 'klant@voorbeeld.nl'],
                'subject' => 'Nieuwe aanvraag via het contactformulier (voorbeeld)',
                'error' => 'SMTP Error: Could not authenticate. (voorbeeld)',
                'backup_error' => '',
                'diagnose_code' => 'auth',
                'initiator' => 'Testmelding',
                'is_test' => true,
                'time' => $now,
            ],
            'suppressed' => 0,
            'since' => null,
            'failures' => 1,
            'digest' => false,
            'panel' => false,
            'notify' => true,
        ];
    }

    /** @return list<array<string, mixed>>|null resultaat per kanaal van de laatste testmelding van deze gebruiker (eenmalig) */
    public static function takeTestResult(): ?array
    {
        $result = get_transient(self::testKey());
        delete_transient(self::testKey());

        return is_array($result) ? $result : null;
    }

    private static function testKey(): string
    {
        return 'nb_ocm_smtp_alert_test_' . get_current_user_id();
    }
}
