<?php

namespace App\Services\Places;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaceVocabularyService
{
    /**
     * @return array{streets: string[], neighborhoods: string[], landmarks: string[], local_names: string[]}
     */
    public function build(float $lat, float $lng, float $radiusKm = 10): array
    {
        $cacheKey = sprintf('places:%.3f:%.3f:%.0f', $lat, $lng, $radiusKm);

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($lat, $lng, $radiusKm) {
            $radiusM = (int) ($radiusKm * 1000);
            $query = <<<QL
[out:json][timeout:25];
(
  way["highway"](around:{$radiusM},{$lat},{$lng});
  node["place"~"suburb|neighbourhood|neighborhood|quarter|village"](around:{$radiusM},{$lat},{$lng});
  node["tourism"~"attraction|museum|viewpoint"](around:{$radiusM},{$lat},{$lng});
  node["amenity"~"place_of_worship|hospital|school|university|police"](around:{$radiusM},{$lat},{$lng});
);
out tags center 80;
QL;

            try {
                $response = Http::asForm()
                    ->timeout(30)
                    ->post(config('services.overpass.url'), ['data' => $query]);
            } catch (\Throwable $e) {
                Log::warning('Overpass failed', ['error' => $e->getMessage()]);

                return $this->empty();
            }

            if (! $response->successful()) {
                return $this->empty();
            }

            $streets = [];
            $neighborhoods = [];
            $landmarks = [];
            $localNames = [];

            foreach ($response->json('elements') ?? [] as $el) {
                $tags = $el['tags'] ?? [];
                $names = $this->collectNames($tags);
                foreach ($names as $name) {
                    $localNames[] = $name;
                }
                if (! empty($tags['highway']) && ! empty($tags['name'])) {
                    $streets[] = $tags['name'];
                } elseif (! empty($tags['place'])) {
                    $neighborhoods = array_merge($neighborhoods, $names);
                } else {
                    $landmarks = array_merge($landmarks, $names);
                }
            }

            return [
                'streets' => array_values(array_unique(array_slice($streets, 0, 40))),
                'neighborhoods' => array_values(array_unique(array_slice($neighborhoods, 0, 30))),
                'landmarks' => array_values(array_unique(array_slice($landmarks, 0, 30))),
                'local_names' => array_values(array_unique(array_slice($localNames, 0, 80))),
            ];
        });
    }

    private function collectNames(array $tags): array
    {
        $names = [];
        foreach ($tags as $key => $value) {
            if ($key === 'name' || str_starts_with((string) $key, 'name:')) {
                if (is_string($value) && $value !== '') {
                    $names[] = $value;
                }
            }
        }

        return $names;
    }

    private function empty(): array
    {
        return [
            'streets' => [],
            'neighborhoods' => [],
            'landmarks' => [],
            'local_names' => [],
        ];
    }
}
