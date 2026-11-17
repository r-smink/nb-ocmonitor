<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Smtp;

use NextBuzz\OcMonitor\Client\Admin\Ui;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;
use NextBuzz\OcMonitor\Client\Tweaks;

/**
 * De SMTP-module. Het panel zet hem aan met de schakelaar smtp_module (tweaks.set); daarna staat
 * de tab SMTP in de clientpagina. De SMTP-gegevens worden alleen hier op de site ingevuld; het
 * panel ziet nooit een wachtwoord.
 */
final class Module
{
    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public static function enabled(): bool
    {
        return (bool) (Tweaks::current()['smtp_module'] ?? false);
    }

    /**
     * Neemt de module de verzending nu echt over?
     *
     * @return array{active: bool, reason: ?string}
     */
    public static function state(): array
    {
        if (!self::enabled()) {
            return ['active' => false, 'reason' => 'De SMTP-module staat uit in het panel.'];
        }
        $conflicts = Conflicts::active();
        if ($conflicts !== []) {
            return ['active' => false, 'reason' => 'Er is al een andere mailplugin actief: ' . implode(', ', $conflicts) . '. Zet die eerst uit; tot dan verstuurt die plugin de mail.'];
        }
        if (!Options::isComplete()) {
            return ['active' => false, 'reason' => 'Nog niet ingesteld: vul server, poort en login in. Tot dan verstuurt WordPress mail zoals voorheen.'];
        }
        if (Options::get()['auth'] && Options::password() === null) {
            return ['active' => false, 'reason' => 'Het opgeslagen wachtwoord is niet te ontsleutelen (andere NB_OCM_SMTP_KEY?). Vul het opnieuw in.'];
        }

        return ['active' => true, 'reason' => null];
    }

    /** @return array<string, mixed> voor de inventaris van het panel; zonder geheimen */
    public static function summary(): array
    {
        if (!self::enabled()) {
            return ['enabled' => false];
        }
        $o = Options::get();
        $state = self::state();

        return [
            'enabled' => true,
            'active' => $state['active'],
            'reason' => $state['reason'],
            'host' => $o['host'],
            'port' => $o['port'],
            'encryption' => $o['encryption'],
            'from_email' => $o['from_email'],
            'conflicts' => Conflicts::active(),
            'last_test' => self::lastTest(),
        ];
    }

    public function register(): void
    {
        if (!self::enabled()) {
            return;
        }
        $active = self::state()['active'];
        if ($active) {
            (new Mailer(Options::get(), Options::password()))->register();
        }
        // Volgorde is het contract (zie docs/smtp-module.md): eerst de log/interceptor, dan backup, dan meldingen.
        (new Log\LogModule($this->recorder))->register($active);
        (new Backup\BackupModule($this->recorder))->register($active);
        (new Alerts\AlertsModule($this->recorder))->register($active);
        if (is_admin()) {
            add_action('admin_post_nb_ocm_smtp_save', [$this, 'handleSave']);
            add_action('admin_post_nb_ocm_smtp_test', [$this, 'handleTest']);
            add_action('admin_post_nb_ocm_smtp_check', [$this, 'handleCheck']);
        }
    }

    public function handleSave(): void
    {
        Ui::guard('nb_ocm_smtp_save');
        $post = wp_unslash($_POST);
        $input = is_array($post['smtp'] ?? null) ? $post['smtp'] : [];
        $change = Options::save($input);
        $from = Options::describe($change['from']);
        $to = Options::describe($change['to']);
        $passChanged = $change['from']['pass'] !== $change['to']['pass'];
        if ($from !== $to || $passChanged || $change['from'] !== $change['to']) {
            $this->recorder->record('ocm.smtp_changed', 'smtp', $from, $to . ($passChanged ? ' (wachtwoord gewijzigd)' : ''));
        }
        $submitted = (string) ($post['smtp']['from_email'] ?? '');
        $warn = $submitted !== '' && $change['to']['from_email'] === '' ? ' Het afzenderadres is ongeldig en niet opgeslagen.' : '';
        Ui::redirect('smtp', $warn === '' ? 'success' : 'error', 'SMTP-instellingen opgeslagen.' . $warn);
    }

    public function handleTest(): void
    {
        Ui::guard('nb_ocm_smtp_test');
        $to = sanitize_email((string) wp_unslash($_POST['to'] ?? ''));
        if ($to === '' || !is_email($to)) {
            Ui::redirect('smtp', 'error', 'Vul een geldig e-mailadres in voor de testmail.');
        }
        $state = self::state();
        if (!$state['active']) {
            Ui::redirect('smtp', 'error', (string) $state['reason']);
        }
        $result = TestMail::send($to, Options::get(), Options::password());
        set_transient(self::resultKey(), ['type' => 'test'] + $result, 15 * MINUTE_IN_SECONDS);
        update_option('nb_ocm_smtp_last_test', ['ok' => $result['ok'], 'time' => $result['time'], 'error' => mb_substr($result['error'], 0, 300)], false);
        $this->recorder->record('ocm.smtp_test', $to, null, null, $result['ok'] ? 'success' : 'failed', $result['ok'] ? [] : ['error' => mb_substr($result['error'], 0, 300)]);
        Ui::redirect('smtp', $result['ok'] ? 'success' : 'error', $result['ok']
            ? sprintf('Testmail verstuurd naar %s. Controleer of hij aankomt (ook in de spammap).', $to)
            : 'De testmail is niet verstuurd. Zie de uitleg hieronder.');
    }

    public function handleCheck(): void
    {
        Ui::guard('nb_ocm_smtp_check');
        $result = Preflight::run(Options::get(), Options::password());
        set_transient(self::resultKey(), ['type' => 'check'] + $result, 15 * MINUTE_IN_SECONDS);
        Ui::redirect('smtp', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'De verbinding met de SMTP-server werkt.' : 'De verbinding met de SMTP-server lukt niet. Zie de stappen hieronder.');
    }

    /** @return array<string, mixed>|null resultaat van de laatste test of check van deze gebruiker (eenmalig) */
    public static function takeResult(): ?array
    {
        $result = get_transient(self::resultKey());
        delete_transient(self::resultKey());

        return is_array($result) ? $result : null;
    }

    /** @return array<string, mixed>|null */
    public static function lastTest(): ?array
    {
        $last = get_option('nb_ocm_smtp_last_test');

        return is_array($last) ? $last : null;
    }

    private static function resultKey(): string
    {
        return 'nb_ocm_smtp_result_' . get_current_user_id();
    }
}
