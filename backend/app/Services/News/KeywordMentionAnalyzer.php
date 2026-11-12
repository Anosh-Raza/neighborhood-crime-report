<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeywordMentionAnalyzer
{
    /**
     * @param  array{url: string, title: string, source_name?: string, published_at?: ?string, snippet?: ?string}  $article
     * @param  string[]  $keywords
     * @return array{
     *   url: string,
     *   title: string,
     *   source_name: string,
     *   published_at: ?string,
     *   snippet: ?string,
     *   matched_keywords: string[],
     *   title_mentions: int,
     *   body_mentions: int,
     *   total_mentions: int,
     *   in_title: bool,
     *   score: float
     * }
     */
    public function analyze(array $article, array $keywords): array
    {
        $title = (string) ($article['title'] ?? '');
        $snippet = (string) ($article['snippet'] ?? '');
        // Use RSS/Google News snippet only — full-page fetches are too slow for sync queue.
        $body = $snippet;

        $titleMentions = 0;
        $bodyMentions = 0;
        $matched = [];

        foreach ($keywords as $keyword) {
            $k = trim($keyword);
            if ($k === '') {
                continue;
            }
            $tCount = $this->countMentions($title, $k);
            $bCount = $this->countMentions($body, $k);
            if ($tCount + $bCount > 0) {
                $matched[] = $k;
            }
            $titleMentions += $tCount;
            $bodyMentions += $bCount;
        }

        $total = $titleMentions + $bodyMentions;
        // Title hits weigh more — they are how Google News surfaces the area.
        $score = round(($titleMentions * 3) + $bodyMentions + (count($matched) * 0.5), 2);

        return [
            'url' => (string) $article['url'],
            'title' => $title,
            'source_name' => (string) ($article['source_name'] ?? 'News'),
            'published_at' => $article['published_at'] ?? null,
            'snippet' => mb_substr(trim(strip_tags($body !== '' ? $body : $snippet)), 0, 320),
            'matched_keywords' => array_values(array_unique($matched)),
            'title_mentions' => $titleMentions,
            'body_mentions' => $bodyMentions,
            'total_mentions' => $total,
            'in_title' => $titleMentions > 0,
            'score' => $score,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $articles
     * @param  string[]  $keywords
     * @return array{articles: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function analyzeMany(array $articles, array $keywords): array
    {
        $scored = [];
        foreach ($articles as $article) {
            $row = $this->analyze($article, $keywords);
            if ($row['total_mentions'] <= 0 && ! $row['in_title']) {
                // Keep Google News hits even if excerpt fetch failed — RSS already matched query.
                if ($this->titleLooksRelevant($row['title'], $keywords)) {
                    $row['in_title'] = true;
                    $row['title_mentions'] = max(1, $row['title_mentions']);
                    $row['total_mentions'] = max(1, $row['total_mentions']);
                    $row['matched_keywords'] = $row['matched_keywords'] ?: array_slice($keywords, 0, 1);
                    $row['score'] = max($row['score'], 3);
                } else {
                    continue;
                }
            }
            $scored[] = $row;
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $keywordTotals = [];
        foreach ($keywords as $keyword) {
            $keywordTotals[$keyword] = [
                'keyword' => $keyword,
                'articles' => 0,
                'title_mentions' => 0,
                'body_mentions' => 0,
            ];
        }

        foreach ($scored as $row) {
            foreach ($row['matched_keywords'] as $kw) {
                if (! isset($keywordTotals[$kw])) {
                    $keywordTotals[$kw] = [
                        'keyword' => $kw,
                        'articles' => 0,
                        'title_mentions' => 0,
                        'body_mentions' => 0,
                    ];
                }
                $keywordTotals[$kw]['articles']++;
                $keywordTotals[$kw]['title_mentions'] += $this->countMentions($row['title'], $kw);
                $keywordTotals[$kw]['body_mentions'] += $this->countMentions((string) $row['snippet'], $kw);
            }
        }

        return [
            'articles' => $scored,
            'totals' => [
                'article_count' => count($scored),
                'title_hit_count' => count(array_filter($scored, fn ($a) => $a['in_title'])),
                'total_mentions' => array_sum(array_column($scored, 'total_mentions')),
                'by_keyword' => array_values($keywordTotals),
            ],
        ];
    }

    private function titleLooksRelevant(string $title, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($this->countMentions($title, $keyword) > 0) {
                return true;
            }
            // Soft match on stem (Saadi)
            $stem = preg_replace('/\s+(garden|town|society|phase|colony)$/i', '', $keyword) ?? $keyword;
            if (mb_strlen($stem) >= 4 && $this->countMentions($title, $stem) > 0) {
                return true;
            }
        }

        return false;
    }

    private function countMentions(string $haystack, string $needle): int
    {
        if ($haystack === '' || $needle === '') {
            return 0;
        }

        $pattern = '/'.preg_quote($needle, '/').'/iu';
        if (! preg_match_all($pattern, $haystack, $m)) {
            return 0;
        }

        return count($m[0]);
    }

    private function fetchExcerpt(string $url): string
    {
        try {
            $body = Http::timeout(12)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; NeighborhoodCrimeReport/1.0)',
                    'Accept' => 'text/html',
                ])
                ->get($url)
                ->body();
            $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body) ?? $body;
            $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $body) ?? $body;
            $text = trim(html_entity_decode(strip_tags($body)));

            return mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 4000);
        } catch (\Throwable $e) {
            Log::debug('Keyword excerpt fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return '';
        }
    }
}
