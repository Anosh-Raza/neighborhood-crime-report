<?php

namespace App\Services\News;

class NewsSearchService
{
    /** @param  iterable<NewsProviderInterface>  $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param  array{streets?: string[], neighborhoods?: string[], landmarks?: string[], local_names?: string[]}  $vocabulary
     * @return array<int, array{url: string, title: string, source_name: string, language?: string, published_at?: string, snippet?: string}>
     */
    public function searchForReport(array $vocabulary, ?string $countryCode, ?string $city, int $limit): array
    {
        $placeNames = array_values(array_unique(array_filter(array_merge(
            [$city ?? ''],
            $vocabulary['neighborhoods'] ?? [],
            $vocabulary['landmarks'] ?? [],
            array_slice($vocabulary['streets'] ?? [], 0, 10),
            array_slice($vocabulary['local_names'] ?? [], 0, 15),
        ))));

        $crimeKeywords = $this->crimeKeywords($countryCode);
        $enabled = config('crime_report.news_providers');
        $byUrl = [];

        foreach ($this->providers as $provider) {
            if (! in_array($provider->name(), $enabled, true)) {
                continue;
            }
            foreach ($provider->search($placeNames, $crimeKeywords, $countryCode, $limit) as $article) {
                $byUrl[$article['url']] = $article;
            }
        }

        return array_slice(array_values($byUrl), 0, $limit);
    }

    /**
     * @return array<int, string>
     */
    public function crimeKeywords(?string $countryCode): array
    {
        $base = [
            'crime', 'robbery', 'burglary', 'theft', 'assault', 'homicide', 'murder',
            'shooting', 'stabbing', 'arson', 'kidnapping', 'fraud', 'vandalism',
        ];

        $localized = match (strtoupper((string) $countryCode)) {
            'FR' => ['criminalité', 'vol', 'cambriolage', 'agression', 'meurtre'],
            'PK', 'IN' => ['چوری', 'ڈکیتی', 'قتل', 'हत्या', 'चोरी', 'डकैती'],
            'IR' => ['جرم', 'سرقت', 'قتل', 'اعتصاب'],
            'MY' => ['jenayah', 'rompak', 'curi', 'bunuh'],
            'ZA' => ['misdaad', 'diefstal', 'aanranding'],
            default => [],
        };

        return array_values(array_unique(array_merge($base, $localized)));
    }
}
