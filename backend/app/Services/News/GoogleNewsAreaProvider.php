<?php

namespace App\Services\News;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fast news search via Google News RSS — parallel feeds, few queries.
 */
class GoogleNewsAreaProvider
{
    /**
     * @param  string[]  $searchTerms
     * @return array<int, array{url: string, title: string, source_name: string, language?: string, published_at?: ?string, snippet?: ?string, query_term: string}>
     */
    public function search(array $searchTerms, ?string $countryCode = null, int $limit = 30): array
    {
        $locale = $this->locale($countryCode);
        $queries = $this->buildQueries($searchTerms);
        $feedUrls = [];
        foreach ($queries as $term) {
            foreach ($this->feedUrls($term, $locale) as $url) {
                $feedUrls[$url] = $term;
            }
        }

        if ($feedUrls === []) {
            return [];
        }

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $url) => $pool->as($url)
                    ->withHeaders($headers)
                    ->timeout(8)
                    ->connectTimeout(4)
                    ->get($url),
                array_keys($feedUrls)
            ));
        } catch (\Throwable $e) {
            Log::warning('News pool failed', ['error' => $e->getMessage()]);

            return [];
        }

        $byUrl = [];
        foreach ($feedUrls as $url => $term) {
            $response = $responses[$url] ?? null;
            if (! $response || ! method_exists($response, 'successful') || ! $response->successful()) {
                continue;
            }

            $xml = $response->body();
            if (! preg_match_all('/<item>(.*?)<\/item>/is', $xml, $items)) {
                continue;
            }

            foreach ($items[1] as $item) {
                $title = $this->tag($item, 'title');
                $link = $this->tag($item, 'link');
                $desc = $this->tag($item, 'description');
                $pub = $this->tag($item, 'pubDate');
                $source = $this->tag($item, 'source') ?: 'News';
                if (! $link || ! $title) {
                    continue;
                }
                $articleUrl = html_entity_decode($link);
                if (isset($byUrl[$articleUrl])) {
                    continue;
                }
                $byUrl[$articleUrl] = [
                    'url' => $articleUrl,
                    'title' => html_entity_decode(strip_tags($title)),
                    'source_name' => html_entity_decode(strip_tags($source)),
                    'language' => null,
                    'published_at' => $pub ?: null,
                    'snippet' => mb_substr(trim(strip_tags(html_entity_decode($desc))), 0, 400),
                    'query_term' => $term,
                ];
                if (count($byUrl) >= $limit) {
                    return array_values($byUrl);
                }
            }
        }

        return array_values($byUrl);
    }

    /**
     * @param  string[]  $searchTerms
     * @return string[]
     */
    private function buildQueries(array $searchTerms): array
    {
        $primary = trim((string) ($searchTerms[0] ?? ''));
        if ($primary === '') {
            return [];
        }

        $out = [$primary]; // unquoted first — matches News tab

        $tokens = array_values(array_filter(preg_split('/\s+/', $primary) ?: [], function ($t) {
            $t = mb_strtolower($t);

            return mb_strlen($t) >= 4
                && ! in_array($t, ['phase', 'block', 'town', 'garden', 'society', 'colony', 'sector', 'scheme', 'near', 'area'], true);
        }));

        if (isset($tokens[0]) && strcasecmp($tokens[0], $primary) !== 0) {
            $out[] = $tokens[0];
        }

        // One alias if useful and different
        foreach (array_slice($searchTerms, 1, 2) as $alias) {
            $alias = trim($alias);
            if ($alias !== '' && strcasecmp($alias, $primary) !== 0 && ! in_array($alias, $out, true)) {
                $out[] = $alias;
                break;
            }
        }

        return array_slice($out, 0, 3);
    }

    /**
     * @return string[]
     */
    private function feedUrls(string $term, array $locale): array
    {
        $q = rawurlencode($term);
        $hl = $locale['hl'];
        $gl = $locale['gl'];
        $ceid = rawurlencode($locale['ceid']);

        return [
            "https://news.google.com/rss/search?q={$q}&hl={$hl}&gl={$gl}&ceid={$ceid}",
        ];
    }

    /**
     * @return array{hl: string, gl: string, ceid: string}
     */
    private function locale(?string $countryCode): array
    {
        return match (strtoupper((string) $countryCode)) {
            'PK' => ['hl' => 'en-PK', 'gl' => 'PK', 'ceid' => 'PK:en'],
            'IN' => ['hl' => 'en-IN', 'gl' => 'IN', 'ceid' => 'IN:en'],
            'GB' => ['hl' => 'en-GB', 'gl' => 'GB', 'ceid' => 'GB:en'],
            'US' => ['hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'],
            'AU' => ['hl' => 'en-AU', 'gl' => 'AU', 'ceid' => 'AU:en'],
            'CA' => ['hl' => 'en-CA', 'gl' => 'CA', 'ceid' => 'CA:en'],
            'NZ' => ['hl' => 'en-NZ', 'gl' => 'NZ', 'ceid' => 'NZ:en'],
            'AE' => ['hl' => 'en-AE', 'gl' => 'AE', 'ceid' => 'AE:en'],
            'SG' => ['hl' => 'en-SG', 'gl' => 'SG', 'ceid' => 'SG:en'],
            'MY' => ['hl' => 'en-MY', 'gl' => 'MY', 'ceid' => 'MY:en'],
            'JP' => ['hl' => 'ja', 'gl' => 'JP', 'ceid' => 'JP:ja'],
            'DE' => ['hl' => 'de', 'gl' => 'DE', 'ceid' => 'DE:de'],
            'FR' => ['hl' => 'fr', 'gl' => 'FR', 'ceid' => 'FR:fr'],
            'IT' => ['hl' => 'it', 'gl' => 'IT', 'ceid' => 'IT:it'],
            'ES' => ['hl' => 'es', 'gl' => 'ES', 'ceid' => 'ES:es'],
            'PT' => ['hl' => 'pt-PT', 'gl' => 'PT', 'ceid' => 'PT:pt'],
            'NL' => ['hl' => 'nl', 'gl' => 'NL', 'ceid' => 'NL:nl'],
            'IE' => ['hl' => 'en-IE', 'gl' => 'IE', 'ceid' => 'IE:en'],
            'PL' => ['hl' => 'pl', 'gl' => 'PL', 'ceid' => 'PL:pl'],
            'SE' => ['hl' => 'sv', 'gl' => 'SE', 'ceid' => 'SE:sv'],
            'NO' => ['hl' => 'no', 'gl' => 'NO', 'ceid' => 'NO:no'],
            'BE' => ['hl' => 'nl', 'gl' => 'BE', 'ceid' => 'BE:nl'],
            'AT' => ['hl' => 'de', 'gl' => 'AT', 'ceid' => 'AT:de'],
            'SA' => ['hl' => 'ar', 'gl' => 'SA', 'ceid' => 'SA:ar'],
            'EG' => ['hl' => 'ar', 'gl' => 'EG', 'ceid' => 'EG:ar'],
            default => ['hl' => 'en', 'gl' => 'US', 'ceid' => 'US:en'],
        };
    }

    private function tag(string $xml, string $tag): string
    {
        if (preg_match('/<'.$tag.'[^>]*><!\[CDATA\[(.*?)\]\]><\/'.$tag.'>/is', $xml, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/<'.$tag.'[^>]*>(.*?)<\/'.$tag.'>/is', $xml, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
