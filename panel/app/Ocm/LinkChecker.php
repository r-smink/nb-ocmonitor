<?php

namespace App\Ocm;

use App\Models\Site;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Kapotte links en afbeeldingen, gecontroleerd vanaf het panel (geen belasting voor de database
 * van de site). Loopt de sitemap af, haalt de pagina's op en controleert elke link en afbeelding.
 * Uitkomsten van externe links worden een week onthouden. 401/403/429 en dergelijke tellen als
 * "niet te controleren", niet als kapot: veel sites blokkeren controles door een programma.
 */
final class LinkChecker
{
    private const UA = 'Mozilla/5.0 (compatible; NB-OC-Monitor-LinkCheck/1.0; +https://www.nextbuzz.nl)';
    private const UNKNOWN = [401, 403, 405, 406, 429, 451, 999];

    /** @return array{pages: int, links: int, broken: int} */
    public static function scan(Site $site): array
    {
        $maxPages = (int) config('ocm.links.max_pages', 250);
        $maxLinks = (int) config('ocm.links.max_links', 3000);
        $base = rtrim($site->url, '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));

        $pages = self::pagesFromSitemap($base, $maxPages);
        if ($pages === []) {
            $pages = [$base . '/'];
        }

        // Pagina's ophalen en links verzamelen: url => [kind, internal, gevonden op (max 10)].
        $found = [];
        foreach (array_chunk($pages, 8) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($url) => $pool->as($url)->withHeaders(['User-Agent' => self::UA])->timeout(20)->connectTimeout(8)->get($url),
                $chunk
            ));
            foreach ($chunk as $page) {
                $r = $responses[$page] ?? null;
                if (!$r instanceof Response || !$r->successful() || !str_contains(strtolower((string) $r->header('Content-Type')), 'html')) {
                    continue;
                }
                foreach (self::extract((string) $r->body(), $page) as [$url, $kind]) {
                    if (count($found) >= $maxLinks && !isset($found[$url])) {
                        continue;
                    }
                    $found[$url] ??= ['kind' => $kind, 'internal' => strtolower((string) parse_url($url, PHP_URL_HOST)) === $host, 'on' => []];
                    if (count($found[$url]['on']) < 10 && !in_array($page, $found[$url]['on'], true)) {
                        $found[$url]['on'][] = $page;
                    }
                }
            }
        }

        $results = self::check(array_keys($found), $found);
        $now = now();
        $broken = 0;
        $seen = [];
        foreach ($results as $url => [$verdict, $code, $error]) {
            if ($verdict !== 'broken') {
                continue;
            }
            $broken++;
            $hash = sha1($url);
            $seen[] = $hash;
            $existing = DB::table('broken_links')->where('site_id', $site->id)->where('url_hash', $hash)->first();
            $row = ['url' => mb_substr($url, 0, 2048), 'kind' => $found[$url]['kind'], 'internal' => $found[$url]['internal'], 'status_code' => $code,
                'error' => $error ? mb_substr($error, 0, 200) : null, 'found_on' => json_encode($found[$url]['on'], JSON_UNESCAPED_SLASHES), 'last_seen_at' => $now];
            if ($existing) {
                DB::table('broken_links')->where('id', $existing->id)->update($row);
            } else {
                DB::table('broken_links')->insert($row + ['site_id' => $site->id, 'url_hash' => $hash, 'first_seen_at' => $now]);
            }
        }
        // Wat niet meer kapot is (of niet meer op de site staat) verdwijnt.
        DB::table('broken_links')->where('site_id', $site->id)->whereNotIn('url_hash', $seen ?: ['-'])->delete();

        return ['pages' => count($pages), 'links' => count($found), 'broken' => $broken];
    }

    /**
     * @param string[] $urls
     * @param array<string, array{kind: string, internal: bool, on: string[]}> $found
     * @return array<string, array{0: string, 1: ?int, 2: ?string}> url => [verdict, status, fout]
     */
    private static function check(array $urls, array $found): array
    {
        $out = [];
        $todo = [];
        $cacheUntil = now()->subDays((int) config('ocm.links.cache_days', 7));
        $cached = DB::table('link_cache')->whereIn('url_hash', array_map('sha1', $urls))->where('checked_at', '>=', $cacheUntil)->get()->keyBy('url_hash');
        foreach ($urls as $url) {
            $c = $cached->get(sha1($url));
            // Interne links altijd opnieuw; externe uit de cache als dat kan.
            if ($c && !$found[$url]['internal']) {
                $out[$url] = [$c->verdict, $c->status_code, $c->error];
            } else {
                $todo[] = $url;
            }
        }
        foreach (array_chunk($todo, 10) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($url) => $pool->as($url)->withHeaders(['User-Agent' => self::UA])->timeout(15)->connectTimeout(8)->withOptions(['allow_redirects' => ['max' => 5]])->head($url),
                $chunk
            ));
            $retry = [];
            foreach ($chunk as $url) {
                $r = $responses[$url] ?? null;
                if ($r instanceof Response && in_array($r->status(), [403, 404, 405, 501], true)) {
                    $retry[] = $url; // sommige servers kennen HEAD niet goed: nog een keer met GET
                    continue;
                }
                $out[$url] = self::verdict($r);
            }
            if ($retry !== []) {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn ($url) => $pool->as($url)->withHeaders(['User-Agent' => self::UA, 'Range' => 'bytes=0-1023'])->timeout(15)->connectTimeout(8)->get($url),
                    $retry
                ));
                foreach ($retry as $url) {
                    $out[$url] = self::verdict($responses[$url] ?? null);
                }
            }
            foreach ($chunk as $url) {
                [$verdict, $code, $error] = $out[$url];
                DB::table('link_cache')->upsert([['url_hash' => sha1($url), 'status_code' => $code, 'error' => $error ? mb_substr($error, 0, 200) : null, 'verdict' => $verdict, 'checked_at' => now()]], ['url_hash'], ['status_code', 'error', 'verdict', 'checked_at']);
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: ?int, 2: ?string} */
    private static function verdict(mixed $r): array
    {
        if (!$r instanceof Response) {
            $message = $r instanceof \Throwable ? $r->getMessage() : 'geen antwoord';
            // DNS-fouten en geweigerde verbindingen zijn echt kapot; time-outs niet zeker.
            // LinkedIn en co. antwoorden met onofficiële codes (999); dat is geen kapotte link.
            $timeout = stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false || stripos($message, 'status code must be') !== false;

            return [$timeout ? 'unknown' : 'broken', null, mb_substr(preg_replace('/\s+/', ' ', $message), 0, 200)];
        }
        $code = $r->status();
        if ($code < 400) {
            return ['ok', $code, null];
        }

        return [in_array($code, self::UNKNOWN, true) ? 'unknown' : 'broken', $code, null];
    }

    /** @return string[] pagina-URL's uit de sitemap (WordPress, Yoast, Rank Math), alleen eigen host */
    public static function pagesFromSitemap(string $base, int $max): array
    {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        // Rank Math en Yoast gebruiken sitemap_index.xml, WordPress zelf wp-sitemap.xml. De eerste die werkt telt.
        $roots = [$base . '/sitemap_index.xml', $base . '/wp-sitemap.xml', $base . '/sitemap.xml'];
        $queue = $roots;
        $pages = [];
        $visited = 0;
        $rootFound = false;
        while ($queue !== [] && count($pages) < $max && $visited < 40) {
            $url = array_shift($queue);
            if ($rootFound && in_array($url, $roots, true)) {
                continue;
            }
            $visited++;
            try {
                $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)->get($url);
            } catch (\Throwable) {
                continue;
            }
            if (!$r->successful() || !str_contains((string) $r->body(), '<')) {
                continue;
            }
            $xml = @simplexml_load_string((string) $r->body(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->children() as $node) {
                $loc = trim((string) $node->loc);
                if ($loc === '' || strtolower((string) parse_url($loc, PHP_URL_HOST)) !== $host) {
                    continue;
                }
                if ($node->getName() === 'sitemap') {
                    $queue[] = $loc;
                } elseif (count($pages) < $max) {
                    $pages[] = $loc;
                }
            }
            if (in_array($url, $roots, true)) {
                $rootFound = true;
            }
        }

        return array_values(array_unique($pages));
    }

    /**
     * Links (a href) en afbeeldingen (img src) uit een HTML-pagina, als absolute URL's.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function extract(string $html, string $pageUrl): array
    {
        if ($html === '') {
            return [];
        }
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $out = [];
        foreach (['a' => 'href', 'img' => 'src'] as $tag => $attr) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                $abs = self::absolute(trim((string) $el->getAttribute($attr)), $pageUrl);
                if ($abs !== null) {
                    $out[$abs] = [$abs, $tag === 'a' ? 'link' : 'image'];
                }
            }
        }

        return array_values($out);
    }

    public static function absolute(string $href, string $page): ?string
    {
        if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|tel|javascript|data|sms|whatsapp|skype):#i', $href)) {
            return null;
        }
        $href = preg_replace('/#.*$/', '', $href);
        if (preg_match('#^https?://#i', $href)) {
            $url = $href;
        } elseif (str_starts_with($href, '//')) {
            $url = parse_url($page, PHP_URL_SCHEME) . ':' . $href;
        } else {
            $p = parse_url($page);
            $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
            if (str_starts_with($href, '/')) {
                $url = $origin . $href;
            } else {
                $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
                $url = $origin . $dir . $href;
            }
        }
        // ./ en ../ oplossen
        $parts = parse_url($url);
        if (!isset($parts['host'])) {
            return null;
        }
        $segments = [];
        foreach (explode('/', $parts['path'] ?? '/') as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }
        $path = implode('/', $segments) ?: '/';

        return strtolower($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($path[0] === '/' ? $path : '/' . $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
