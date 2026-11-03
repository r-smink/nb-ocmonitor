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
        'cover_text_color' => '#1F2937',
    ];

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
                default => is_string($value) ? mb_substr(trim($value), 0, $key === 'intro' ? 2000 : 200) : $default,
            };
        }

        return $out;
    }
}
