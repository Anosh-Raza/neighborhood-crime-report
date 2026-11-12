<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GdeltNewsProvider implements NewsProviderInterface
{
    public function name(): string
    {
        return 'gdelt';
    }

    public function search(array $placeNames, array $crimeKeywords, ?string $country = null, int $limit = 20): array
    {
        $places = array_slice(array_values(array_filter($placeNames)), 0, 6);
        $crimes = array_slice(array_values(array_filter($crimeKeywords)), 0, 6);
        if ($places === [] || $crimes === []) {
            return [];
        }

        $query = '('.implode(' OR ', array_map(fn ($p) => '"'.$p.'"', $places)).')'
            .' ('.implode(' OR ', $crimes).')';

        try {
            $response = Http::timeout(25)->get(config('services.gdelt.base_url'), [
                'query' => $query,
                'mode' => 'ArtList',
                'format' => 'json',
                'maxrecords' => min($limit, 50),
                'sort' => 'HybridRel',
            ]);
        } catch (\Throwable $e) {
            Log::warning('GDELT search failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $articles = [];
        foreach ($response->json('articles') ?? [] as $row) {
            $url = $row['url'] ?? null;
            if (! $url) {
                continue;
            }
            $articles[] = [
                'url' => $url,
                'title' => (string) ($row['title'] ?? 'Untitled'),
                'source_name' => (string) ($row['domain'] ?? 'GDELT'),
                'language' => isset($row['language']) ? strtolower((string) $row['language']) : null,
                'published_at' => $row['seendate'] ?? null,
                'snippet' => null,
            ];
        }

        return $articles;
    }
}
