<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Admin;

/** Tab Systeem: systeemstatus en support-export. */
final class SystemTab
{
    public const EXPORT_ACTION = 'nb_ocm_support_export';

    /** admin-post-handlers registreren (de download van de support-export). */
    public static function register(): void
    {
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'handleExport']);
    }

    public static function handleExport(): void
    {
        Ui::guard(self::EXPORT_ACTION);
        $facts = SystemStatus::collect();
        $text = SupportExport::build($facts);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . SupportExport::filename((string) $facts['home_url'], (int) $facts['generated_at']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($text));
        echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- platte tekst als download
        exit;
    }

    public static function render(): void
    {
        $facts = SystemStatus::collect();
        self::renderFacts($facts, SupportExport::build($facts));
    }

    /**
     * Opbouw van de pagina uit de feiten (los aan te roepen voor een preview).
     *
     * @param array<string, mixed> $facts
     */
    public static function renderFacts(array $facts, string $export): void
    {
        $now = (int) $facts['generated_at'];
        $sections = SystemStatus::evaluate($facts, $now);
        $counts = SystemStatus::counts($sections);
        $last = (int) ($facts['last_panel_request'] ?? 0);

        $panelTone = 'info';
        foreach ($sections['Koppeling met het panel'] as $check) {
            if ($check['label'] === 'Laatste contact') {
                $panelTone = $check['tone'];
            }
        }
        echo '<div class="nbocm-kpis nbocm-sys-kpis">';
        self::kpi('Problemen', (string) $counts['crit'], $counts['crit'] > 0 ? 'crit' : 'ok', $counts['crit'] > 0 ? 'Direct oplossen' : 'Niets kritieks');
        self::kpi('Aandachtspunten', (string) $counts['warn'], $counts['warn'] > 0 ? 'warn' : 'ok', $counts['warn'] > 0 ? 'Bekijk de adviezen' : 'Niets te doen');
        self::kpi('In orde', (string) $counts['ok'], 'info', 'van ' . array_sum($counts) . ' controles');
        self::kpi('Laatste panelcontact', $last > 0 ? SystemStatus::duration(max(0, $now - $last)) . ' geleden' : 'Nog niet', $panelTone, $last > 0 ? 'Geverifieerd verzoek van het panel' : (!empty($facts['paired']) ? 'Gekoppeld, nog geen verzoek' : 'Niet gekoppeld'), true);
        echo '</div>';

        $hints = [
            'WordPress en PHP' => 'Versies, beveiliging en basisinstellingen.',
            'Bronnen' => 'Limieten van de server en vrije ruimte.',
            'PHP-extensies' => 'Wat WordPress en deze plugin nodig hebben.',
            'WP-cron' => 'Geplande taken: updates, opschonen, meldingen.',
            'Koppeling met het panel' => 'Contact met het OC Monitor-panel van Next Buzz.',
        ];
        echo '<div class="nbocm-grid">';
        foreach ($sections as $title => $checks) {
            Ui::cardOpen($title, $hints[$title] ?? '', 'nbocm-sys');
            echo '<ul class="nbocm-checks">';
            foreach ($checks as $c) {
                printf(
                    '<li><span class="nbocm-check-label">%s</span><span class="nbocm-check-body"><strong>%s</strong><small>%s</small></span><span class="nbocm-check-pill">',
                    esc_html($c['label']),
                    esc_html($c['value']),
                    esc_html($c['advice'])
                );
                Ui::pill(SystemStatus::TONE_LABELS[$c['tone']] ?? $c['tone'], $c['tone'] === 'info' ? 'muted' : $c['tone']);
                echo '</span></li>';
            }
            echo '</ul>';
            Ui::cardClose();
        }
        self::exportCard($export);
        echo '</div>';
    }

    private static function exportCard(string $export): void
    {
        Ui::cardOpen('Support-export', 'Een overzicht van deze site om mee te sturen bij een supportvraag aan Next Buzz.', 'is-wide nbocm-export');
        echo '<p>Het rapport bevat versies, plugins en thema, de instellingen van OC Monitor en SMTP, de status van WP-cron, alle controles hierboven en recente PHP-fouten. '
            . 'Wachtwoorden, sleutels, tokens en cookies staan er <strong>nooit</strong> in; e-mailadressen zijn ingekort en webhooks staan er alleen als domeinnaam in.</p>';
        echo '<label class="screen-reader-text" for="nbocm-export-text">Support-export</label>';
        printf('<textarea id="nbocm-export-text" class="nbocm-export-text" rows="14" readonly spellcheck="false">%s</textarea>', esc_html($export));
        echo '<div class="nbocm-actions">';
        echo '<button type="button" class="nbocm-btn is-primary" data-nbocm-copy="nbocm-export-text">Kopiëren</button>';
        printf('<a class="nbocm-btn is-secondary" href="%s">Downloaden (.txt)</a>', esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::EXPORT_ACTION), self::EXPORT_ACTION)));
        echo '<span class="nbocm-copy-status nbocm-muted" role="status" aria-live="polite"></span>';
        echo '</div>';
        Ui::cardClose();
        self::copyScript();
    }

    private static function kpi(string $label, string $value, string $tone, string $sub, bool $isText = false): void
    {
        printf(
            '<div class="nbocm-kpi is-%s"><span class="nbocm-kpi-label">%s</span><strong%s>%s</strong><small>%s</small></div>',
            esc_attr($tone),
            esc_html($label),
            $isText ? ' class="is-text"' : '',
            esc_html($value),
            esc_html($sub)
        );
    }

    /** Kopieerknop: Clipboard API, met select() + execCommand als terugval (http of oudere browsers). */
    private static function copyScript(): void
    {
        echo '<script>(function(){document.querySelectorAll("[data-nbocm-copy]").forEach(function(b){b.addEventListener("click",function(){'
            . 'var t=document.getElementById(b.getAttribute("data-nbocm-copy"));if(!t)return;'
            . 'var s=b.parentNode.querySelector(".nbocm-copy-status");'
            . 'function done(ok){if(s){s.textContent=ok?"Gekopieerd naar het klembord.":"Kopiëren lukt niet; selecteer de tekst en kopieer handmatig.";}}'
            . 'function fallback(){t.focus();t.select();var ok=false;try{ok=document.execCommand("copy");}catch(e){}done(ok);}'
            . 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t.value).then(function(){done(true);},fallback);}else{fallback();}'
            . '});});})();</script>';
    }
}
