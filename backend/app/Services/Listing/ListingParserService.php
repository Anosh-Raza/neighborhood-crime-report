<?php

namespace App\Services\Listing;

use App\Services\Extraction\LlmClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingParserService
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    /**
     * @return array{
     *   listing_url: string,
     *   address?: string,
     *   city?: string,
     *   country?: string,
     *   country_code?: string,
     *   lat?: float,
     *   lng?: float,
     *   title?: string,
     *   source: string
     * }
     */
    public function parse(string $url): array
    {
        $fromUrl = $this->extractFromUrl($url);
        $html = '';

        try {
            $html = $this->fetchHtml($url);
        } catch (\Throwable $e) {
            Log::warning('Listing fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return array_filter(array_merge([
                'listing_url' => $url,
                'source' => 'url_hint',
                'fetch_error' => $e->getMessage(),
            ], $fromUrl), fn ($v) => $v !== null && $v !== '');
        }

        if ($this->looksBlocked($html)) {
            Log::warning('Listing fetch blocked by security page', ['url' => $url]);

            return array_filter(array_merge([
                'listing_url' => $url,
                'source' => 'url_hint_blocked',
            ], $fromUrl), fn ($v) => $v !== null && $v !== '');
        }

        $fromStructured = $this->extractStructured($html, $url);

        if ($this->isComplete($fromStructured)) {
            return $fromStructured;
        }

        // Prefer page content over URL slug hints when the HTML has a usable address.
        $merged = array_filter(
            array_merge($fromUrl, $fromStructured, ['listing_url' => $url]),
            fn ($v) => $v !== null && $v !== ''
        );

        if (! empty($merged['address']) || (! empty($merged['lat']) && ! empty($merged['lng']))) {
            $merged['source'] = $fromStructured['source'] ?? ($merged['source'] ?? 'structured');

            return $merged;
        }

        $fromLlm = $this->extractWithLlm($html, $url);

        return array_filter(
            array_merge($merged, $fromLlm, ['listing_url' => $url]),
            fn ($v) => $v !== null && $v !== ''
        );
    }

    private function fetchHtml(string $url): string
    {
        $request = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ])->timeout(25);

        if (app()->environment('local')) {
            $request = $request->withOptions(['verify' => false]);
        }

        $response = $request->get($url);

        if (! $response->successful() && ! in_array($response->status(), [403, 503], true)) {
            throw new \RuntimeException('Unable to fetch listing URL (HTTP '.$response->status().').');
        }

        // Some portals return 503 challenge pages with HTML bodies we can detect.
        if (! $response->successful() && $response->body() === '') {
            throw new \RuntimeException('Unable to fetch listing URL (HTTP '.$response->status().').');
        }

        // Keep enough of large marketplace pages (OLX ~1MB) to retain JSON-LD location blocks.
        return mb_substr($response->body(), 0, 1500000);
    }

    private function looksBlocked(string $html): bool
    {
        $hay = mb_strtolower($html);
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = mb_strtolower(trim(html_entity_decode(strip_tags($m[1]))));
        }

        // Only treat as a bot wall when the page itself is a challenge — many
        // marketplaces load captcha scripts on otherwise valid listing HTML.
        if (str_contains($title, 'security check')
            || str_contains($title, 'attention required')
            || str_contains($title, 'access denied')
            || str_contains($title, 'just a moment')) {
            return true;
        }

        if (str_contains($hay, 'cf-browser-verification') || str_contains($hay, 'cf-challenge')) {
            return true;
        }

        if (str_contains($hay, 'security check') && str_contains($hay, 'zameen') && ! str_contains($hay, 'application/ld+json')) {
            return true;
        }

        return false;
    }

    /**
     * Infer a searchable address when portals block scraping (e.g. Zameen URL slug).
     *
     * @return array<string, mixed>
     */
    private function extractFromUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $path = urldecode((string) parse_url($url, PHP_URL_PATH));

        if (str_contains($host, 'zameen.com')) {
            $area = app(\App\Services\Places\AreaKeywordService::class)->resolve($url, null, null, 'PK');
            if (! empty($area['primary']) && $area['primary'] !== 'Unknown area') {
                $cityHint = $area['city'] ?: $this->guessPakistanCity($area['primary']);
                $address = $area['geocode_query']
                    ?? trim($area['primary'].($cityHint ? ', '.$cityHint : '').', Pakistan');

                return [
                    'address' => $address,
                    'city' => $cityHint,
                    'country' => 'Pakistan',
                    'country_code' => 'PK',
                    'title' => $area['primary'],
                    'source' => 'zameen_url',
                    'area_keyword' => $area['primary'],
                ];
            }

            // Fallback slug parse if area resolver fails.
            if (preg_match('#/Property/([^/]+?)(?:-\d+)+#i', $path, $m) || preg_match('#/Property/([^/?]+)#i', $path, $m)) {
                $slug = $m[1];
                $slug = preg_replace('/_+/', ' ', $slug) ?? $slug;
                $slug = preg_replace('/\s*-\s*/', ' ', $slug) ?? $slug;
                $slug = preg_replace('/\b(for sale|for rent|commercial|residential|plot|house|flat|apartment|bedroom|sqyd|sqyds|marla|kanal|\d+\s*ft|road facing).*$/i', '', $slug) ?? $slug;
                $slug = trim(preg_replace('/\s+/', ' ', $slug) ?? $slug);
                $slug = $this->dedupeWords($slug);

                if ($slug !== '') {
                    $cityHint = $this->guessPakistanCity($slug);

                    return [
                        'address' => $cityHint
                            ? ucwords($slug).', '.$cityHint.', Pakistan'
                            : ucwords($slug).', Pakistan',
                        'city' => $cityHint,
                        'country' => 'Pakistan',
                        'country_code' => 'PK',
                        'title' => ucwords($slug),
                        'source' => 'zameen_url',
                    ];
                }
            }
        }

        return [];
    }

    private function dedupeWords(string $text): string
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($text))) ?: [];
        if (count($words) < 2) {
            return trim($text);
        }

        // Remove consecutive duplicated bigrams / repeated half.
        $joined = implode(' ', $words);
        if (preg_match('/^(.+?)\s+\1(\s+.+)?$/u', $joined, $m)) {
            $joined = trim($m[1].($m[2] ?? ''));
        }

        return $joined;
    }

    private function guessPakistanCity(string $slug): ?string
    {
        $s = mb_strtolower($slug);
        $cities = [
            'karachi' => 'Karachi',
            'lahore' => 'Lahore',
            'islamabad' => 'Islamabad',
            'rawalpindi' => 'Rawalpindi',
            'peshawar' => 'Peshawar',
            'multan' => 'Multan',
            'faisalabad' => 'Faisalabad',
        ];
        foreach ($cities as $needle => $label) {
            if (str_contains($s, $needle)) {
                return $label;
            }
        }

        // Common Karachi societies often appear without the city name in the slug.
        foreach (['saadi garden', 'bahria town', 'gulshan', 'clifton', 'dha', 'scheme 33', 'north nazimabad', 'gulistan'] as $area) {
            if (str_contains($s, $area)) {
                return 'Karachi';
            }
        }

        return null;
    }

    private function extractStructured(string $html, string $url): array
    {
        $result = [
            'listing_url' => $url,
            'source' => 'structured',
        ];

        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $json) {
                $data = json_decode(html_entity_decode(trim($json)), true);
                if (! is_array($data)) {
                    continue;
                }
                $this->walkJsonLd($data, $result);
            }
        }

        // Marketplace embeds (OLX / similar) often put area in flat JSON keys.
        foreach ([
            'locationName', 'area_name', 'neighbourhood', 'neighborhood', 'mapAddress', 'ad_location', 'locality',
        ] as $key) {
            if (preg_match('/"'.$key.'"\s*:\s*"([^"]+)"/i', $html, $m)) {
                $result['address'] ??= html_entity_decode($m[1]);
            }
        }
        if (preg_match('/"city_name"\s*:\s*"([^"]+)"/i', $html, $m)) {
            $result['city'] ??= html_entity_decode($m[1]);
        }
        if (preg_match('/"country_code"\s*:\s*"([A-Za-z]{2})"/i', $html, $m)) {
            $result['country_code'] ??= strtoupper($m[1]);
        }

        $ogs = [];
        if (preg_match_all('/<meta[^>]+property=["\']og:([^"\']+)["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $ogMatches, PREG_SET_ORDER)) {
            foreach ($ogMatches as $m) {
                $ogs[$m[1]] = html_entity_decode($m[2]);
            }
        }
        // Also support <meta property=... content=...> attribute order variants / name=
        if (preg_match('/<meta[^>]+(?:property|name)=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $result['title'] ??= html_entity_decode($m[1]);
        }
        if (preg_match('/<meta[^>]+(?:property|name)=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $result['description'] ??= html_entity_decode($m[1]);
        }
        $result['title'] ??= $ogs['title'] ?? null;
        if (! empty($ogs['latitude']) && ! empty($ogs['longitude'])) {
            $result['lat'] ??= (float) $ogs['latitude'];
            $result['lng'] ??= (float) $ogs['longitude'];
        }

        if (preg_match('/["\'](?:latitude|lat)["\']\s*[:=]\s*([-+]?\d{1,3}\.\d+)/i', $html, $latM)
            && preg_match('/["\'](?:longitude|lng|lon)["\']\s*[:=]\s*([-+]?\d{1,3}\.\d+)/i', $html, $lngM)) {
            $result['lat'] ??= (float) $latM[1];
            $result['lng'] ??= (float) $lngM[1];
        }

        // Pull area from listing description prose when structured address is thin.
        if (empty($result['address']) && ! empty($result['description'])) {
            if (preg_match('/(?:location|area|prime location)\s*[:\-–]\s*([^\n\*\.]{5,80})/i', $result['description'], $m)) {
                $result['address'] = trim($m[1]);
            }
        }

        // Country code from short country field.
        if (! empty($result['country']) && empty($result['country_code'])) {
            $c = strtoupper((string) $result['country']);
            if (strlen($c) === 2) {
                $result['country_code'] = $c;
                $result['country'] = $c === 'PK' ? 'Pakistan' : $result['country'];
            }
        }

        return array_filter($result, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Recursively collect address / geo / title from nested JSON-LD (OLX nests Apartment under RealEstateListing).
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $result
     */
    private function walkJsonLd(array $node, array &$result): void
    {
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                if (is_array($child)) {
                    $this->walkJsonLd($child, $result);
                }
            }
        }

        $type = $node['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];

        $result['title'] ??= $node['name'] ?? $node['headline'] ?? null;
        if (! empty($node['description']) && is_string($node['description'])) {
            $result['description'] ??= $node['description'];
        }

        $addr = $node['address'] ?? null;
        if (is_string($addr) && $addr !== '') {
            $result['address'] ??= $addr;
        } elseif (is_array($addr)) {
            $joined = $this->joinAddress($addr);
            if ($joined !== '') {
                $result['address'] ??= $joined;
            }
            $result['city'] ??= $addr['addressLocality'] ?? null;
            $country = $addr['addressCountry'] ?? null;
            if (is_array($country)) {
                $result['country'] ??= $country['name'] ?? null;
                $result['country_code'] ??= isset($country['@id']) ? null : ($country['name'] ?? null);
            } elseif (is_string($country)) {
                if (strlen($country) === 2) {
                    $result['country_code'] ??= strtoupper($country);
                    $result['country'] ??= strtoupper($country) === 'PK' ? 'Pakistan' : $country;
                } else {
                    $result['country'] ??= $country;
                }
            }
        }

        $geo = $node['geo'] ?? null;
        if (is_array($geo)) {
            $result['lat'] ??= isset($geo['latitude']) ? (float) $geo['latitude'] : null;
            $result['lng'] ??= isset($geo['longitude']) ? (float) $geo['longitude'] : null;
        }

        // Prefer RealEstateListing / Apartment / House / Place nodes by continuing into mainEntity.
        foreach (['mainEntity', 'itemOffered', 'about', 'containsPlace'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                $this->walkJsonLd($node[$key], $result);
            }
        }

        // Walk other nested arrays/objects lightly (depth-limited by useful keys only).
        foreach ($node as $key => $value) {
            if ($key === '@graph' || $key === 'mainEntity') {
                continue;
            }
            if (is_array($value) && (isset($value['@type']) || isset($value['address']) || isset($value['geo']))) {
                $this->walkJsonLd($value, $result);
            }
        }

        unset($types);
    }

    private function extractWithLlm(string $html, string $url): array
    {
        $snippet = $this->stripToText($html);
        $prompt = file_get_contents(base_path('prompts/listing_extract.txt'));
        $user = "Listing URL: {$url}\n\nPage text excerpt:\n".mb_substr($snippet, 0, 12000);

        try {
            $json = $this->llm->jsonCompletion(
                purpose: 'listing_extract',
                systemPrompt: $prompt ?: 'Extract listing location as JSON.',
                userPrompt: $user,
                reportId: null,
            );
        } catch (\Throwable $e) {
            Log::warning('Listing LLM extract failed', ['error' => $e->getMessage()]);

            return ['listing_url' => $url, 'source' => 'llm_failed'];
        }

        return [
            'listing_url' => $url,
            'address' => $json['address'] ?? null,
            'city' => $json['city'] ?? null,
            'country' => $json['country'] ?? null,
            'country_code' => $json['country_code'] ?? null,
            'lat' => isset($json['lat']) ? (float) $json['lat'] : null,
            'lng' => isset($json['lng']) ? (float) $json['lng'] : null,
            'title' => $json['title'] ?? null,
            'source' => 'llm',
        ];
    }

    private function joinAddress(array $addr): string
    {
        return implode(', ', array_filter([
            $addr['streetAddress'] ?? null,
            $addr['addressLocality'] ?? null,
            $addr['addressRegion'] ?? null,
            $addr['postalCode'] ?? null,
            is_array($addr['addressCountry'] ?? null)
                ? ($addr['addressCountry']['name'] ?? null)
                : ($addr['addressCountry'] ?? null),
        ]));
    }

    private function stripToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html)));
    }

    private function isComplete(array $data): bool
    {
        $hasCoords = isset($data['lat'], $data['lng']);
        $hasAddress = ! empty($data['address']);

        return $hasCoords && $hasAddress;
    }
}
