<?php

namespace App\Ocm;

use App\Models\Site;

/**
 * De kant-en-klare schakelaars die de client-plugin (0.10.0+) kent, met uitleg. Gelijk houden
 * met Tweaks::DEFINITIONS in de plugin.
 */
final class TweakCatalog
{
    public const MIN_VERSION = '0.10.0';

    /** key => [label, uitleg, groep] (bool-schakelaars) */
    public const SWITCHES = [
        'disable_comments' => ['Reacties uitzetten', 'Geen reacties en pingbacks meer, en het menu Reacties verdwijnt uit WP-admin.', 'Inhoud'],
        'hide_admin_bar' => ['Adminbalk alleen voor medewerkers', 'Abonnees en klanten van een webshop zien de zwarte balk bovenaan niet.', 'Inhoud'],
        'hide_wp_version' => ['WordPress-versie verbergen', 'Haalt het versienummer uit de broncode, zodat aanvallers minder weten.', 'Beveiliging'],
        'generic_login_errors' => ['Algemene foutmelding bij inloggen', 'Verraadt niet meer of een gebruikersnaam bestaat.', 'Beveiliging'],
        'disable_file_edit' => ['Bestandseditor uitzetten', 'Geen code meer bewerken via WP-admin (DISALLOW_FILE_EDIT).', 'Beveiliging'],
        'security_headers' => ['Beveiligingsheaders', 'Stuurt X-Frame-Options, X-Content-Type-Options, Referrer-Policy en Permissions-Policy mee.', 'Beveiliging'],
        'disable_app_passwords' => ['Applicatiewachtwoorden uitzetten', 'Geen inloggen met applicatiewachtwoorden (gebruikt door de WordPress-app en sommige koppelingen).', 'Beveiliging'],
        'block_php_uploads' => ['Geen PHP in de uploadmap', 'Voorkomt dat geüploade PHP-bestanden worden uitgevoerd (Apache/LiteSpeed; bij Nginx in de serverconfig).', 'Beveiliging'],
        'clean_head' => ['Broncode opschonen', 'Haalt RSD-, WLW-, shortlink- en REST-links uit de head.', 'Snelheid'],
        'disable_emojis' => ['Emoji-script uitzetten', 'Scheelt een script en een stylesheet op elke pagina.', 'Snelheid'],
        'disable_embeds' => ['Embeds uitzetten', 'Geen wp-embed-script en oEmbed-links (andere sites kunnen berichten dan niet insluiten).', 'Snelheid'],
    ];

    public const HEARTBEAT = ['default' => 'WordPress-standaard', 'slow' => 'Trager (elke 60 seconden)', 'off_frontend' => 'Uit op de voorkant'];

    public const REVISIONS = [-1 => 'WordPress-standaard (onbeperkt)', 0 => 'Geen revisies', 3 => '3 per bericht', 5 => '5 per bericht', 10 => '10 per bericht', 25 => '25 per bericht'];

    public static function blockedReason(Site $site): ?string
    {
        if (!$site->isPaired()) {
            return 'Site is niet gekoppeld';
        }
        if ($site->plugin_version === null || version_compare($site->plugin_version, self::MIN_VERSION, '<')) {
            return 'Vanaf client-plugin ' . self::MIN_VERSION . ' (deze site: ' . ($site->plugin_version ?? '?') . ')';
        }

        return $site->hasScope('maintenance') ? null : 'Onderhoud staat niet aan op de site';
    }

    /**
     * Invoer van een formulier omzetten naar de argumenten voor tweaks.set.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function fromForm(array $input): array
    {
        $out = [];
        foreach (array_keys(self::SWITCHES) as $key) {
            $out[$key] = !empty($input[$key]);
        }
        $out['heartbeat'] = isset(self::HEARTBEAT[$input['heartbeat'] ?? '']) ? $input['heartbeat'] : 'default';
        $out['revisions'] = isset($input['revisions']) && is_numeric($input['revisions']) && array_key_exists((int) $input['revisions'], self::REVISIONS) ? (int) $input['revisions'] : -1;

        return $out;
    }
}
