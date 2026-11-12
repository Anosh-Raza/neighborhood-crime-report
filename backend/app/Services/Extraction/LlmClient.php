<?php

namespace App\Services\Extraction;

use App\Models\LlmLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlmClient
{
    public function jsonCompletion(string $purpose, string $systemPrompt, string $userPrompt, ?string $reportId = null): array
    {
        $cfg = config('crime_report.llm');
        if (empty($cfg['api_key'])) {
            // Local/dev fallback so the app can boot without a paid LLM key.
            return match ($purpose) {
                'listing_extract' => [
                    'address' => null,
                    'city' => null,
                    'country' => null,
                    'country_code' => null,
                    'lat' => null,
                    'lng' => null,
                    'title' => null,
                ],
                'incident_extract' => $this->mockIncidentExtract($userPrompt),
                default => ['incidents' => []],
            };
        }

        $response = Http::withToken($cfg['api_key'])
            ->timeout(60)
            ->acceptJson()
            ->post($cfg['base_url'].'/chat/completions', [
                'model' => $cfg['model'],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => $cfg['max_tokens'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        $tokensIn = (int) data_get($response->json(), 'usage.prompt_tokens', 0);
        $tokensOut = (int) data_get($response->json(), 'usage.completion_tokens', 0);
        $cost = $this->estimateCost($tokensIn, $tokensOut);

        if (! $response->successful()) {
            LlmLog::query()->create([
                'report_id' => $reportId,
                'purpose' => $purpose,
                'model' => $cfg['model'],
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_usd' => $cost,
                'success' => false,
                'error' => mb_substr($response->body(), 0, 1000),
            ]);
            throw new \RuntimeException('LLM request failed: HTTP '.$response->status());
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '{}');
        $json = json_decode($content, true);
        if (! is_array($json)) {
            Log::warning('LLM returned non-JSON', ['purpose' => $purpose, 'content' => mb_substr($content, 0, 500)]);
            $json = [];
        }

        LlmLog::query()->create([
            'report_id' => $reportId,
            'purpose' => $purpose,
            'model' => $cfg['model'],
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd' => $cost,
            'success' => true,
            'error' => null,
        ]);

        return $json;
    }

    private function estimateCost(int $tokensIn, int $tokensOut): float
    {
        // Approximate gpt-4o-mini pricing; logged for monitoring, not billing.
        return round(($tokensIn * 0.00000015) + ($tokensOut * 0.0000006), 6);
    }

    /**
     * Heuristic local mock when LLM_API_KEY is empty — only emits an incident
     * if the article title/snippet already contains an explicit crime keyword.
     */
    private function mockIncidentExtract(string $userPrompt): array
    {
        $payload = json_decode($userPrompt, true);
        if (! is_array($payload)) {
            return ['incidents' => []];
        }

        $article = $payload['article'] ?? [];
        $title = (string) ($article['title'] ?? '');
        $excerpt = (string) ($article['excerpt'] ?? '');
        $hay = mb_strtolower($title.' '.$excerpt);

        $types = [
            'murder' => 'homicide',
            'homicide' => 'homicide',
            'killed' => 'homicide',
            'shooting' => 'shooting',
            'shot' => 'shooting',
            'robbery' => 'robbery',
            'robbed' => 'robbery',
            'theft' => 'theft',
            'stolen' => 'theft',
            'burglary' => 'burglary',
            'assault' => 'assault',
            'kidnap' => 'kidnapping',
            'extortion' => 'extortion',
            'drug' => 'drugs',
            'narcotic' => 'drugs',
            'rape' => 'sexual assault',
            'fraud' => 'fraud',
            'arson' => 'arson',
            'crime' => 'unspecified crime',
        ];

        $crimeType = null;
        foreach ($types as $needle => $label) {
            if (str_contains($hay, $needle)) {
                $crimeType = $label;
                break;
            }
        }

        if (! $crimeType) {
            return ['incidents' => []];
        }

        $property = $payload['property'] ?? [];
        $place = (string) ($property['city'] ?? $property['address'] ?? 'the area');
        $summary = trim(preg_replace('/\s+/', ' ', $title)) ?: 'Crime-related news item near '.$place.'.';

        return [
            'incidents' => [[
                'crime_type' => $crimeType,
                'incident_date' => null,
                'location_text' => $place,
                'precision' => 'neighborhood-only',
                'status' => 'allegation',
                'language' => $article['language'] ?? 'en',
                'summary' => mb_substr($summary, 0, 220),
            ]],
        ];
    }
}
