<?php

namespace App\Services\News;

interface NewsProviderInterface
{
    /**
     * @param  array<int, string>  $placeNames
     * @param  array<int, string>  $crimeKeywords
     * @return array<int, array{url: string, title: string, source_name: string, language?: string, published_at?: string, snippet?: string}>
     */
    public function search(array $placeNames, array $crimeKeywords, ?string $country = null, int $limit = 20): array;

    public function name(): string;
}
