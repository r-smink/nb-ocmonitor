<?php

namespace App\Ocm\Reports;

use App\Models\Setting;

/** Opmaak van de klantrapporten: kleuren, fonts en teksten. Bestanden staan in ReportAsset. */
final class ReportDesign
{
    public const KEY = 'report_design';

    /** mPDF-fonts die altijd beschikbaar zijn, plus "custom" voor een geüpload font. */
    public const FONTS = [
        'dejavusans' => 'DejaVu Sans',
        'dejavusanscondensed' => 'DejaVu Sans Condensed',
        'dejavuserif' => 'DejaVu Serif',
        'freesans' => 'FreeSans (Helvetica-achtig)',
        'freeserif' => 'FreeSerif (Times-achtig)',
        'custom' => 'Eigen font (upload)',
    ];

    public const DEFAULTS = [
        'company' => 'Next Buzz',
        'title' => 'Onderhoudsrapport',
        'intro' => 'In dit rapport lees je welk onderhoud we de afgelopen periode aan je website hebben uitgevoerd en hoe de site ervoor staat.',
        'footer' => 'Next Buzz · www.nextbuzz.nl',
        'color_primary' => '#E22830',
        'color_accent' => '#1F2937',
        'color_text' => '#1F2937',
        'color_muted' => '#6B7280',
        'font_heading' => 'dejavusans',
        'font_body' => 'dejavusans',
        'font_size' => 10,
        'margin_top' => 30,
        'margin_bottom' => 22,
        'margin_side' => 18,
        // Wit op de donkere Next Buzz-voorpagina. Zonder eigen achtergrond valt ReportPdf terug op color_text.
        'cover_text_color' => '#FFFFFF',
        // Naam op de voorpagina, in de voettekst en de bestandsnaam: klantnaam of domeinnaam.
        'report_name' => 'customer',
        // De e-mail bij het rapport; {domein}, {klant} enz. worden ingevuld (zie PLACEHOLDERS).
        'mail_subject' => '{titel} {domein} – {maand}',
        'mail_body' => "Beste {contactpersoon},

In de bijlage vind je het onderhoudsrapport van {domein} over de periode {periode}.

Heb je vragen over het rapport? Beantwoord dan gewoon deze e-mail.

Met vriendelijke groet,

{bedrijf}",
    ];

    public const REPORT_NAMES = ['customer' => 'Klantnaam', 'domain' => 'Domeinnaam (van de sites)'];

    /** Invulvelden voor de e-mail. */
    public const PLACEHOLDERS = [
        '{domein}' => 'domeinnaam van de site(s), bijv. bakkerij.nl',
        '{klant}' => 'klantnaam',
        '{contactpersoon}' => 'contactpersoon (of de klantnaam)',
        '{periode}' => 'bijv. 16-07-2026 t/m 15-10-2026',
        '{maand}' => 'maand van het onderhoud, bijv. oktober 2026',
        '{titel}' => 'titel van het rapport',
        '{bedrijf}' => 'jullie bedrijfsnaam',
    ];

    /** Vult de invulvelden in een onderwerp of e-mailtekst. */
    public static function fill(string $template, \App\Models\Customer $customer, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to, ?array $design = null): string
    {
        $design ??= self::get();

        return strtr($template, [
            '{domein}' => $customer->domainLabel(),
            '{klant}' => (string) $customer->name,
            '{contactpersoon}' => (string) ($customer->contact_name ?: $customer->name),
            '{periode}' => $from->format('d-m-Y') . ' t/m ' . $to->format('d-m-Y'),
            '{maand}' => $to->locale('nl')->translatedFormat('F Y'),
            '{titel}' => (string) $design['title'],
            '{bedrijf}' => (string) $design['company'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        return self::normalize(Setting::get(self::KEY));
    }

    /** @param array<string, mixed> $input */
    public static function save(array $input): array
    {
        $design = self::normalize($input);
        Setting::put(self::KEY, $design);

        return $design;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $input[$key] ?? $default;
            $out[$key] = match (true) {
                str_starts_with($key, 'color_') || $key === 'cover_text_color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $default,
                str_starts_with($key, 'font_') && $key !== 'font_size' => is_string($value) && isset(self::FONTS[$value]) ? $value : $default,
                $key === 'font_size' => is_numeric($value) ? max(8, min(14, (int) $value)) : $default,
                str_starts_with($key, 'margin_') => is_numeric($value) ? max(5, min(80, (int) $value)) : $default,
                $key === 'report_name' => is_string($value) && isset(self::REPORT_NAMES[$value]) ? $value : $default,
                $key === 'mail_body' => is_string($value) && trim($value) !== '' ? mb_substr(str_replace("
", "
", trim($value)), 0, 5000) : $default,
                $key === 'mail_subject' => is_string($value) && trim($value) !== '' ? mb_substr(preg_replace('/[
]+/', ' ', trim($value)), 0, 200) : $default,
                default => is_string($value) ? mb_substr(trim($value), 0, $key === 'intro' ? 2000 : 200) : $default,
            };
        }

        return $out;
    }
}
