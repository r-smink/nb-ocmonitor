<?php

namespace App\Ocm\Reports;

use App\Models\ReportAsset;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Symfony\Component\Process\Process;

/**
 * Zet de rapportgegevens om naar een PDF met mPDF. Achtergronden mogen een afbeelding (JPG/PNG)
 * of een PDF zijn; van een PDF wordt de eerste pagina als sjabloon onder elke pagina gelegd.
 */
final class ReportPdf
{
    /**
     * @param array<string, mixed> $data uit ReportData::build() of ReportPdf::demoData()
     */
    public static function render(array $data, ?array $design = null): string
    {
        $design ??= ReportDesign::get();
        $assets = ReportAsset::query()->get()->keyBy('kind');
        $dir = self::workDir();

        $files = [];
        foreach ($assets as $kind => $asset) {
            $ext = match ($asset->mime) {
                'application/pdf' => 'pdf',
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                default => 'ttf',
            };
            $path = $dir . '/' . $kind . '-' . substr(sha1($asset->data), 0, 12) . '.' . $ext;
            if (!is_file($path)) {
                file_put_contents($path, $asset->bytes());
            }
            $files[$kind] = $path;
        }

        $fontdata = (new FontVariables())->getDefaults()['fontdata'];
        if (isset($files['font_regular'])) {
            $fontdata['custom'] = ['R' => basename($files['font_regular']), 'B' => basename($files['font_bold'] ?? $files['font_regular'])];
        }
        foreach (['font_heading', 'font_body'] as $key) {
            if ($design[$key] === 'custom' && !isset($fontdata['custom'])) {
                $design[$key] = 'dejavusans';
            }
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $dir . '/tmp',
            'fontDir' => array_merge((new ConfigVariables())->getDefaults()['fontDir'], [$dir]),
            'fontdata' => $fontdata,
            'default_font' => $design['font_body'],
            'default_font_size' => $design['font_size'],
            'margin_top' => $design['margin_top'],
            'margin_bottom' => $design['margin_bottom'],
            'margin_left' => $design['margin_side'],
            'margin_right' => $design['margin_side'],
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);
        $mpdf->SetTitle($design['title'] . ' ' . $data['customer']['name']);
        $mpdf->SetAuthor($design['company']);
        $mpdf->SetCreator('NB OC Monitor');

        $view = ['d' => $data, 'design' => $design, 'logo' => $files['logo'] ?? null,
            'coverImage' => isset($files['background_cover']) && !$assets['background_cover']->isPdf() ? $files['background_cover'] : null,
            'pageImage' => isset($files['background_page']) && !$assets['background_page']->isPdf() ? $files['background_page'] : null];

        $coverTpl = self::template($mpdf, $files['background_cover'] ?? null, $assets->get('background_cover'));
        $pageTpl = self::template($mpdf, $files['background_page'] ?? null, $assets->get('background_page'));

        $mpdf->WriteHTML(view('reports.pdf.style', $view)->render(), \Mpdf\HTMLParserMode::HEADER_CSS);
        if ($coverTpl !== null) {
            $mpdf->SetPageTemplate($coverTpl);
        }
        $mpdf->AddPage();
        $mpdf->WriteHTML(view('reports.pdf.cover', $view)->render(), \Mpdf\HTMLParserMode::HTML_BODY);

        $mpdf->SetPageTemplate($pageTpl ?? '');
        $mpdf->AddPage();
        $mpdf->SetHTMLFooter(view('reports.pdf.footer', $view)->render());
        $mpdf->WriteHTML(view('reports.pdf.body', $view)->render(), \Mpdf\HTMLParserMode::HTML_BODY);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Controleert een geüploade PDF-achtergrond en zet hem zo nodig om (qpdf) naar een vorm die
     * FPDI kan lezen. Geeft de bruikbare bytes terug, of gooit een fout met uitleg.
     */
    public static function preparePdfBackground(string $bytes): string
    {
        $dir = self::workDir();
        $in = $dir . '/upload-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($in, $bytes);
        try {
            if (self::readable($in)) {
                return $bytes;
            }
            $out = $in . '.qpdf.pdf';
            $process = new Process(['qpdf', '--object-streams=disable', '--decrypt', $in, $out]);
            try {
                $process->setTimeout(30)->run();
            } catch (\Throwable) {
                // qpdf niet geïnstalleerd
            }
            if (is_file($out) && self::readable($out)) {
                $converted = (string) file_get_contents($out);
                @unlink($out);

                return $converted;
            }
            @unlink($out);
            throw new \RuntimeException('Deze PDF kan niet als achtergrond gebruikt worden. Exporteer hem als PDF 1.4 of upload een JPG of PNG (A4, 2480 × 3508 px).');
        } finally {
            @unlink($in);
        }
    }

    /** Demogegevens voor het voorbeeld op de rapportpagina. */
    public static function demoData(): array
    {
        $now = \Carbon\CarbonImmutable::now();
        $site = fn (string $name, string $url) => [
            'name' => $name, 'url' => $url, 'wp' => '6.9', 'php' => '8.3.14', 'plugins' => 18, 'themes' => 2, 'https' => true,
            'updates' => [
                ['date' => $now->subDays(20), 'kind' => 'Plugin', 'name' => 'Yoast SEO', 'from' => '25.1', 'to' => '25.3', 'ok' => true],
                ['date' => $now->subDays(20), 'kind' => 'Plugin', 'name' => 'WooCommerce', 'from' => '9.4.1', 'to' => '9.5.0', 'ok' => true],
                ['date' => $now->subDays(20), 'kind' => 'Thema', 'name' => 'Astra', 'from' => '4.8.3', 'to' => '4.8.6', 'ok' => true],
                ['date' => $now->subDays(3), 'kind' => 'WordPress', 'name' => 'WordPress', 'from' => '6.8.3', 'to' => '6.9', 'ok' => true],
            ],
            'pending' => [['kind' => 'Plugin', 'name' => 'Contact Form 7', 'from' => '6.0.1', 'to' => '6.0.2']],
            'security' => ['failed_logins' => 312, 'locked' => 9, 'channels' => ['xmlrpc' => 240, 'wp-login' => 72], 'issues' => [],
                'login_security' => ['lockout' => true, 'xmlrpc' => 'off', 'hide_users' => true]],
            'pagespeed' => [
                'mobile' => ['label' => 'Mobiel', 'performance' => 78, 'accessibility' => 94, 'best_practices' => 100, 'seo' => 92, 'lcp_ms' => 2650, 'cls' => 0.04, 'before' => 64, 'measured_at' => $now->subDays(2)],
                'desktop' => ['label' => 'Desktop', 'performance' => 96, 'accessibility' => 94, 'best_practices' => 100, 'seo' => 92, 'lcp_ms' => 900, 'cls' => 0.01, 'before' => 95, 'measured_at' => $now->subDays(2)],
            ],
            'changes' => [['date' => $now->subDays(12), 'what' => 'Plugin geïnstalleerd', 'object' => 'Complianz GDPR']],
        ];

        return [
            'customer' => ['name' => 'Voorbeeldklant B.V.', 'contact' => 'Jan Jansen', 'contract' => '4x per jaar'],
            'from' => $now->subMonths(3), 'to' => $now,
            'sections' => array_keys(\App\Models\Customer::REPORT_SECTIONS),
            'sites' => [$site('Voorbeeldklant', 'https://www.voorbeeldklant.nl'), $site('Voorbeeldklant Shop', 'https://shop.voorbeeldklant.nl')],
            'runs' => [['date' => $now->subDays(20), 'done_at' => $now->subDays(20), 'by' => 'Next Buzz', 'notes' => 'Alle plugins en het thema bijgewerkt, back-up gecontroleerd.']],
            'totals' => ['sites' => 2, 'updates' => 8, 'pending' => 2, 'failed_logins' => 624, 'blocked_ips' => 18],
        ];
    }

    private static function template(Mpdf $mpdf, ?string $path, ?ReportAsset $asset): ?string
    {
        if ($path === null || $asset === null || !$asset->isPdf()) {
            return null;
        }
        try {
            $mpdf->setSourceFile($path);

            return $mpdf->importPage(1);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function readable(string $path): bool
    {
        try {
            (new Mpdf(['tempDir' => self::workDir() . '/tmp']))->setSourceFile($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function workDir(): string
    {
        $dir = storage_path('app/reports');
        if (!is_dir($dir . '/tmp')) {
            mkdir($dir . '/tmp', 0770, true);
        }

        return $dir;
    }
}
