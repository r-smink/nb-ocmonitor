<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ReportAsset;
use App\Models\VisualCheck;
use App\Ocm\Reports\ReportData;
use App\Ocm\Reports\ReportDesign;
use App\Ocm\Reports\ReportPdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCoverTest extends TestCase
{
    use RefreshDatabase;

    private function png(int $w, int $h, array $rgb): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function jpg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img);

        return base64_encode((string) ob_get_clean());
    }

    /** Afbeeldingen die als paginapatroon (achtergrond) worden getekend, per patroon. */
    private function backgroundPatterns(string $pdf): int
    {
        return preg_match_all('/\/Type \/Pattern \/PatternType 1/', $pdf);
    }

    public function test_png_backgrounds_are_drawn_on_the_cover_and_on_every_following_page(): void
    {
        foreach (['background_cover' => [27, 26, 23], 'background_page' => [255, 255, 255]] as $kind => $rgb) {
            $png = $this->png(248, 351, $rgb);
            ReportAsset::query()->create(['kind' => $kind, 'filename' => $kind . '.png', 'mime' => 'image/png', 'size' => strlen($png), 'data' => base64_encode($png)]);
        }

        $pdf = ReportPdf::render(ReportPdf::demoData(), ReportDesign::normalize([]));

        $pages = preg_match_all('/\/Type \/Page\b(?!s)/', $pdf);
        $this->assertGreaterThanOrEqual(3, $pages);
        $this->assertSame($pages, $this->backgroundPatterns($pdf), 'elke pagina een achtergrond');
        $this->assertMatchesRegularExpression('#/Width 600\s*/Height 375#', $pdf, 'screenshot van de voorbeeld-homepage op de voorpagina');
    }

    public function test_without_a_cover_background_the_white_cover_text_falls_back_to_the_text_colour(): void
    {
        $this->assertSame('#FFFFFF', ReportDesign::DEFAULTS['cover_text_color']);
        $data = ReportPdf::demoData();
        $data['cover_shot'] = null;
        $pdf = ReportPdf::render($data, ReportDesign::normalize([]));

        $this->assertSame(0, $this->backgroundPatterns($pdf));
        $this->assertDoesNotMatchRegularExpression('#/Width 600\s*/Height 375#', $pdf, 'geen screenshot: geen kader');
    }

    public function test_cover_shot_is_the_latest_after_update_screenshot_cropped_to_the_top(): void
    {
        $site = $this->makePairedSite();
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'info@bakkerij.test']);
        $site->forceFill(['customer_id' => $customer->id])->save();
        VisualCheck::query()->create(['site_id' => $site->id, 'status' => 'done', 'after_image' => $this->jpg(600, 2400), 'after_at' => now()->subDays(5), 'job_ids' => []]);
        $latest = $this->jpg(600, 1800);
        VisualCheck::query()->create(['site_id' => $site->id, 'status' => 'done', 'after_image' => $latest, 'after_at' => now()->subDay(), 'job_ids' => []]);
        VisualCheck::query()->create(['site_id' => $site->id, 'status' => 'skipped', 'job_ids' => []]);

        $data = ReportData::build($customer->fresh(), CarbonImmutable::now()->subMonth(), CarbonImmutable::now());
        $this->assertSame($latest, $data['cover_shot']['image']);
        $this->assertSame('https://klant.example.nl', $data['cover_shot']['url']);

        $pdf = ReportPdf::render($data, ReportDesign::normalize([]));
        $this->assertMatchesRegularExpression('#/Width 600\s*/Height 375#', $pdf, 'bovenste deel van de homepage (16:10)');
    }

    public function test_customer_without_visual_check_has_no_cover_shot(): void
    {
        $site = $this->makePairedSite();
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'info@bakkerij.test']);
        $site->forceFill(['customer_id' => $customer->id])->save();

        $this->assertNull(ReportData::build($customer->fresh(), CarbonImmutable::now()->subMonth(), CarbonImmutable::now())['cover_shot']);
    }
}
