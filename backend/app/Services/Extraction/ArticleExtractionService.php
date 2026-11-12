<?php

namespace App\Services\Extraction;

use App\Models\ArticleCache;
use App\Support\LegalFilters;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ArticleExtractionService
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    /**
     * @param  array{url: string, title: string, source_name: string, language?: string, published_at?: string, snippet?: string}  $article
     * @return array<int, array<string, mixed>>
     */
    public function extract(array $article, string $reportId, array $propertyContext): array
    {
        $cached = ArticleCache::query()->where('url', $article['url'])->first();
        if ($cached && is_array($cached->extracted_json)) {
            return $cached->extracted_json['incidents'] ?? [];
        }

        $excerpt = $article['snippet'] ?? null;
        if (! $excerpt) {
            $excerpt = $this->fetchSnippet($article['url']);
        }

        $prompt = file_get_contents(base_path('prompts/incident_extract.txt')) ?: '';
        $user = json_encode([
            'article' => [
                'url' => $article['url'],
                'title' => $article['title'],
                'source_name' => $article['source_name'],
                'language' => $article['language'] ?? null,
                'published_at' => $article['published_at'] ?? null,
                'excerpt' => mb_substr((string) $excerpt, 0, 4000),
            ],
            'property' => $propertyContext,
            'rules' => [
                'never_invent_incidents',
                'every_incident_needs_source_url',
                'never_name_victims_suspects_or_minors',
                'store_one_sentence_paraphrase_only',
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $json = $this->llm->jsonCompletion('incident_extract', $prompt, (string) $user, $reportId);
        } catch (\Throwable $e) {
            Log::warning('Article extraction failed', ['url' => $article['url'], 'error' => $e->getMessage()]);
            $json = ['incidents' => []];
        }

        $incidents = [];
        foreach ($json['incidents'] ?? [] as $row) {
            if (! is_array($row) || empty($row['crime_type']) || empty($row['summary'])) {
                continue;
            }
            $incidents[] = [
                'crime_type' => (string) $row['crime_type'],
                'incident_date' => $row['incident_date'] ?? null,
                'location_text' => (string) ($row['location_text'] ?? ''),
                'precision' => in_array($row['precision'] ?? '', ['exact', 'street-or-landmark', 'neighborhood-only'], true)
                    ? $row['precision']
                    : 'neighborhood-only',
                'status' => in_array($row['status'] ?? '', ['confirmed', 'allegation', 'investigation'], true)
                    ? $row['status']
                    : 'allegation',
                'language' => $row['language'] ?? ($article['language'] ?? 'en'),
                'summary' => LegalFilters::sanitizeSummary((string) $row['summary']),
                'source' => [
                    'name' => $article['source_name'],
                    'url' => $article['url'],
                    'published_at' => $article['published_at'] ?? null,
                ],
            ];
        }

        ArticleCache::query()->updateOrCreate(
            ['url' => $article['url']],
            [
                'language' => $article['language'] ?? null,
                'title' => $article['title'],
                'source_name' => $article['source_name'],
                'published_at' => $this->parseDate($article['published_at'] ?? null),
                'extracted_json' => ['incidents' => $incidents],
                'fetched_at' => Carbon::now(),
            ]
        );

        return $incidents;
    }

    private function fetchSnippet(string $url): string
    {
        try {
            $body = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'NeighborhoodCrimeReport/1.0'])
                ->get($url)
                ->body();
            $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body) ?? $body;
            $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $body) ?? $body;
            $text = trim(html_entity_decode(strip_tags($body)));

            // Never store full article text long-term; only a short excerpt for LLM input.
            return mb_substr($text, 0, 3500);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
