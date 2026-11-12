<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RssNewsProvider implements NewsProviderInterface
{
    public function name(): string
    {
        return 'rss';
    }

    public function search(array $placeNames, array $crimeKeywords, ?string $country = null, int $limit = 20): array
    {
        $feeds = $this->feedsForCountry($country);
        $needles = array_map('mb_strtolower', array_merge(
            array_slice($placeNames, 0, 8),
            array_slice($crimeKeywords, 0, 8)
        ));

        $articles = [];
        foreach ($feeds as $feedUrl) {
            try {
                $xml = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'NeighborhoodCrimeReport/1.0'])
                    ->get($feedUrl)
                    ->body();
            } catch (\Throwable $e) {
                Log::warning('RSS fetch failed', ['feed' => $feedUrl, 'error' => $e->getMessage()]);
                continue;
            }

            if (! preg_match_all('/<item>(.*?)<\/item>/is', $xml, $items)) {
                preg_match_all('/<entry>(.*?)<\/entry>/is', $xml, $items);
            }

            foreach ($items[1] ?? [] as $item) {
                $title = $this->tag($item, 'title');
                $link = $this->tag($item, 'link') ?: $this->attr($item, 'link', 'href');
                $desc = $this->tag($item, 'description') ?: $this->tag($item, 'summary');
                $pub = $this->tag($item, 'pubDate') ?: $this->tag($item, 'published');
                $hay = mb_strtolower($title.' '.$desc);
                $hit = false;
                foreach ($needles as $n) {
                    if ($n !== '' && str_contains($hay, $n)) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit || ! $link) {
                    continue;
                }
                $articles[] = [
                    'url' => html_entity_decode($link),
                    'title' => html_entity_decode(strip_tags($title)),
                    'source_name' => parse_url($feedUrl, PHP_URL_HOST) ?: 'RSS',
                    'language' => null,
                    'published_at' => $pub ?: null,
                    'snippet' => mb_substr(trim(strip_tags(html_entity_decode($desc))), 0, 280),
                ];
                if (count($articles) >= $limit) {
                    return $articles;
                }
            }
        }

        return $articles;
    }

    private function feedsForCountry(?string $country): array
    {
        $map = [
            'US' => ['https://news.google.com/rss/search?q=crime&hl=en-US&gl=US&ceid=US:en'],
            'GB' => ['https://news.google.com/rss/search?q=crime&hl=en-GB&gl=GB&ceid=GB:en'],
            'FR' => ['https://news.google.com/rss/search?q=criminalit%C3%A9&hl=fr&gl=FR&ceid=FR:fr'],
            'IN' => ['https://news.google.com/rss/search?q=crime&hl=en-IN&gl=IN&ceid=IN:en'],
            'PK' => [
                'https://news.google.com/rss/search?q=Karachi+crime&hl=en-PK&gl=PK&ceid=PK:en',
                'https://news.google.com/rss/search?q=crime+Pakistan&hl=en-PK&gl=PK&ceid=PK:en',
            ],
            'IR' => ['https://news.google.com/rss/search?q=%D8%AC%D8%B1%D9%85&hl=fa&gl=IR&ceid=IR:fa'],
            'MY' => ['https://news.google.com/rss/search?q=crime&hl=en-MY&gl=MY&ceid=MY:en'],
            'NZ' => ['https://news.google.com/rss/search?q=crime&hl=en-NZ&gl=NZ&ceid=NZ:en'],
            'AU' => ['https://news.google.com/rss/search?q=crime&hl=en-AU&gl=AU&ceid=AU:en'],
            'ZA' => ['https://news.google.com/rss/search?q=crime&hl=en-ZA&gl=ZA&ceid=ZA:en'],
        ];

        $code = $country ? strtoupper($country) : null;

        return $map[$code] ?? ['https://news.google.com/rss/search?q=crime&hl=en&gl=US&ceid=US:en'];
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

    private function attr(string $xml, string $tag, string $attr): string
    {
        if (preg_match('/<'.$tag.'[^>]*'.$attr.'=["\']([^"\']+)["\']/i', $xml, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
