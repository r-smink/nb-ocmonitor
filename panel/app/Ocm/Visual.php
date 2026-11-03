<?php

namespace App\Ocm;

use App\Models\Site;
use App\Models\SiteJob;
use App\Models\VisualCheck;
use Illuminate\Support\Facades\Http;

/**
 * Visuele controle bij updates. Screenshots komen van Google PageSpeed Insights (Lighthouse,
 * desktop, volledige pagina), zodat er geen browser op onze server hoeft te draaien.
 *
 * Verloop: Updates::queue houdt de update-jobs even vast en maakt een VisualCheck "before".
 * ocm:visual maakt de eerste screenshot en geeft de jobs vrij ("updating"). Zijn alle jobs klaar,
 * dan volgt de tweede screenshot en de vergelijking ("done"). Lukt een screenshot niet, dan gaan
 * de updates gewoon door ("skipped").
 */
final class Visual
{
    private const WIDTH = 600;
    private const MAX_HEIGHT = 3000;
    private const COMPARE_WIDTH = 150;

    public static function enabled(): bool
    {
        return (bool) config('ocm.visual.enabled') && (string) config('ocm.pagespeed.api_key') !== '' && extension_loaded('gd');
    }

    /** Hoe lang update-jobs maximaal wachten op de eerste screenshot. */
    public static function holdUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('ocm.visual.hold_minutes', 8));
    }

    /** Koppelt nieuwe update-jobs aan een open controle, of maakt er een. Geeft terug of de jobs moeten wachten. */
    public static function attach(Site $site, SiteJob $job): bool
    {
        $check = VisualCheck::query()->where('site_id', $site->id)->whereIn('status', ['before', 'updating'])->latest('id')->first()
            ?? VisualCheck::query()->create(['site_id' => $site->id, 'status' => 'before', 'job_ids' => []]);
        $check->update(['job_ids' => array_values(array_unique(array_merge($check->job_ids ?? [], [$job->id])))]);

        return $check->status === 'before';
    }

    /** Eén stap voor alle open controles; draait elke minuut in de scheduler. */
    public static function tick(): void
    {
        foreach (VisualCheck::query()->with('site')->where('status', 'before')->get() as $check) {
            try {
                $check->forceFill(['before_image' => base64_encode(self::screenshot($check->site)), 'before_at' => now(), 'status' => 'updating'])->save();
            } catch (\Throwable $e) {
                $check->forceFill(['status' => 'skipped', 'error' => 'Eerste screenshot mislukt: ' . mb_substr($e->getMessage(), 0, 400)])->save();
            }
            Updates::releaseReady($check->site);
        }

        foreach (VisualCheck::query()->with('site')->where('status', 'updating')->get() as $check) {
            $jobs = SiteJob::query()->whereIn('id', $check->job_ids ?? [])->get(['id', 'status', 'finished_at']);
            $busy = $jobs->contains(fn (SiteJob $j) => in_array($j->status, ['pending', 'running'], true));
            $lastFinished = $jobs->max('finished_at');
            // Even wachten na de laatste update (caches, inventaris), maar niet eindeloos.
            if ($busy || ($lastFinished && now()->diffInSeconds($lastFinished, true) < (int) config('ocm.visual.settle_seconds', 60))) {
                if ($check->created_at->lt(now()->subHours(3))) {
                    $check->forceFill(['status' => 'skipped', 'error' => 'Updates duurden te lang'])->save();
                }
                continue;
            }
            try {
                $after = self::screenshot($check->site);
                [$percent, $diff] = self::compare((string) base64_decode((string) $check->before_image), $after);
                $check->forceFill(['after_image' => base64_encode($after), 'diff_image' => base64_encode($diff), 'diff_percent' => $percent, 'after_at' => now(), 'status' => 'done'])->save();
                if ($check->changed()) {
                    Activity::log('visual_change', ['percent' => $percent, 'jobs' => $check->job_ids], $check->site, null);
                }
            } catch (\Throwable $e) {
                $check->forceFill(['status' => 'skipped', 'error' => 'Tweede screenshot mislukt: ' . mb_substr($e->getMessage(), 0, 400)])->save();
            }
        }
    }

    /** JPEG van de homepage (desktop), geschaald naar 600 px breed en maximaal 3000 px hoog. */
    public static function screenshot(Site $site): string
    {
        $url = PageSpeed::ENDPOINT . '?' . http_build_query(array_filter([
            'url' => rtrim($site->url, '/') . '/',
            'strategy' => 'desktop',
            'category' => 'performance',
            'key' => config('ocm.pagespeed.api_key') ?: null,
        ]));
        $response = Http::timeout((int) config('ocm.pagespeed.timeout_seconds', 90))->acceptJson()->get($url);
        if (!$response->successful()) {
            throw new \RuntimeException('PageSpeed Insights gaf HTTP ' . $response->status() . ': ' . (string) $response->json('error.message', ''));
        }
        $data = (string) ($response->json('lighthouseResult.fullPageScreenshot.screenshot.data') ?? $response->json('lighthouseResult.audits.final-screenshot.details.data') ?? '');
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $data, $m)) {
            throw new \RuntimeException('Geen screenshot in het antwoord');
        }
        $image = @imagecreatefromstring((string) base64_decode($m[2], true));
        if ($image === false) {
            throw new \RuntimeException('Screenshot niet leesbaar');
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $height = min(self::MAX_HEIGHT, (int) round($h * self::WIDTH / $w));
        $out = imagecreatetruecolor(self::WIDTH, $height);
        imagecopyresampled($out, $image, 0, 0, 0, 0, self::WIDTH, $height, $w, (int) round($height * $w / self::WIDTH));
        ob_start();
        imagejpeg($out, null, 72);

        return (string) ob_get_clean();
    }

    /**
     * Percentage zichtbaar veranderde pixels (op lage resolutie, zodat ruis van compressie wegvalt)
     * en een afbeelding met de veranderingen in rood over de nieuwe screenshot.
     *
     * @return array{0: float, 1: string}
     */
    public static function compare(string $beforeJpeg, string $afterJpeg): array
    {
        $a = imagecreatefromstring($beforeJpeg);
        $b = imagecreatefromstring($afterJpeg);
        if ($a === false || $b === false) {
            throw new \RuntimeException('Screenshots niet leesbaar');
        }
        $w = self::COMPARE_WIDTH;
        $ha = (int) round(imagesy($a) * $w / imagesx($a));
        $hb = (int) round(imagesy($b) * $w / imagesx($b));
        $h = max($ha, $hb);
        $sa = imagescale($a, $w, $ha);
        $sb = imagescale($b, $w, $hb);

        $changed = 0;
        $mask = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                // Wat in de ene versie niet bestaat (langere of kortere pagina) telt als veranderd.
                if ($y >= $ha || $y >= $hb) {
                    $diff = true;
                } else {
                    $ca = imagecolorat($sa, $x, $y);
                    $cb = imagecolorat($sb, $x, $y);
                    $diff = abs((($ca >> 16) & 255) - (($cb >> 16) & 255)) + abs((($ca >> 8) & 255) - (($cb >> 8) & 255)) + abs(($ca & 255) - ($cb & 255)) > 90;
                }
                if ($diff) {
                    $changed++;
                    $mask[] = [$x, $y];
                }
            }
        }
        $percent = round($changed / max(1, $w * $h) * 100, 2);

        // Overlay op de nieuwe screenshot op ware grootte.
        $overlay = imagecreatetruecolor(imagesx($b), imagesy($b));
        imagecopy($overlay, $b, 0, 0, 0, 0, imagesx($b), imagesy($b));
        $red = imagecolorallocatealpha($overlay, 226, 40, 48, 70);
        $scale = imagesx($b) / $w;
        foreach ($mask as [$x, $y]) {
            imagefilledrectangle($overlay, (int) ($x * $scale), (int) ($y * $scale), (int) (($x + 1) * $scale), (int) (($y + 1) * $scale), $red);
        }
        ob_start();
        imagejpeg($overlay, null, 70);

        return [$percent, (string) ob_get_clean()];
    }
}
