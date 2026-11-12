<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Search Google News RSS by area keyword (the primary product algorithm).
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
        $byUrl = [];

        // Primary keyword first, then one alias — keeps local sync under PHP time limits.
        foreach (array_slice($searchTerms, 0, 2) as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }

            foreach ($this->feedUrls($term, $locale) as $feedUrl) {
                try {
                    $xml = Http::timeout(18)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (compatible; NeighborhoodCrimeReport/1.0)',
                            'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
                        ])
                        ->get($feedUrl)
                        ->body();
                } catch (\Throwable $e) {
                    Log::warning('Google News area search failed', ['term' => $term, 'error' => $e->getMessage()]);
                    continue;
                }

                if (! preg_match_all('/<item>(.*?)<\/item>/is', $xml, $items)) {
                    continue;
                }

                foreach ($items[1] as $item) {
                    $title = $this->tag($item, 'title');
                    $link = $this->tag($item, 'link');
                    $desc = $this->tag($item, 'description');
                    $pub = $this->tag($item, 'pubDate');
                    $source = $this->tag($item, 'source') ?: 'Google News';
                    if (! $link || ! $title) {
                        continue;
                    }
                    $url = html_entity_decode($link);
                    if (isset($byUrl[$url])) {
                        continue;
                    }
                    $byUrl[$url] = [
                        'url' => $url,
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
        }

        return array_values($byUrl);
    }

    /**
     * @return string[]
     */
    private function feedUrls(string $term, array $locale): array
    {
        $q = rawurlencode('"'.$term.'"');
        $hl = $locale['hl'];
        $gl = $locale['gl'];
        $ceid = $locale['ceid'];

        // Quoted query (higher precision for area names like "Saadi Town").
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
