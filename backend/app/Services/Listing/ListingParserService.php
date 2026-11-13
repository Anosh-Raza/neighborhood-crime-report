<?php

namespace App\Services\Listing;

use App\Services\Extraction\LlmClient;
use App\Services\Places\AreaKeywordService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingParserService
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly GlobalUrlLocationExtractor $urlExtractor,
    ) {
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
        $hostHints = $this->urlExtractor->countryFromHost(
            preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? ''
        );

        try {
            $html = $this->fetchHtml($url);
        } catch (\Throwable $e) {
            Log::warning('Listing fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return $this->finalize(array_merge($fromUrl, $hostHints, [
                'listing_url' => $url,
                'source' => 'url_hint',
                'fetch_error' => $e->getMessage(),
            ]));
        }

        if ($this->looksBlocked($html)) {
            Log::warning('Listing fetch blocked by security page', ['url' => $url]);

            return $this->finalize(array_merge($fromUrl, $hostHints, [
                'listing_url' => $url,
                'source' => 'url_hint_blocked',
            ]));
        }

        $fromStructured = $this->extractStructured($html, $url);
        $merged = $this->reconcileUrlAndPage($fromUrl, $fromStructured, $hostHints, $url);

        if ($this->isComplete($merged) || ! empty($merged['address'])) {
            return $this->finalize($merged);
        }

        $fromLlm = $this->extractWithLlm($html, $url);

        return $this->finalize($this->reconcileUrlAndPage(
            $fromUrl,
            array_merge($merged, array_filter($fromLlm, fn ($v) => $v !== null && $v !== '')),
            $hostHints,
            $url
        ));
    }

    /**
     * Prefer rich page data when it agrees with URL hints; otherwise trust the URL slug
     * (common when portals serve soft-404 / geo-homepages behind bot walls).
     *
     * @param  array<string, mixed>  $fromUrl
     * @param  array<string, mixed>  $fromPage
     * @param  array<string, mixed>  $hostHints
     * @return array<string, mixed>
     */
    private function reconcileUrlAndPage(array $fromUrl, array $fromPage, array $hostHints, string $url): array
    {
        $page = array_filter($fromPage, fn ($v) => $v !== null && $v !== '');
        $urlData = array_filter($fromUrl, fn ($v) => $v !== null && $v !== '');

        $urlAddress = (string) ($urlData['address'] ?? $urlData['title'] ?? '');
        $pageAddress = (string) ($page['address'] ?? '');
        $urlStrong = ($urlData['source'] ?? '') === 'url_slug' && mb_strlen($urlAddress) >= 12;

        if ($urlStrong && $pageAddress !== '' && ! $this->addressesAgree($urlAddress, $pageAddress)) {
            Log::info('Listing page address mismatches URL slug; preferring URL', [
                'url' => $url,
                'url_address' => $urlAddress,
                'page_address' => $pageAddress,
            ]);

            return $this->urlOverridePayload($hostHints, $urlData, $url);
        }

        // Soft homepage / search shell often has coords but no listing address.
        if ($urlStrong && $pageAddress === '' && (isset($page['lat']) || isset($page['lng']))) {
            return $this->urlOverridePayload($hostHints, $urlData, $url);
        }

        $source = $page['source'] ?? ($urlData['source'] ?? 'structured');

        return $this->preferRich(array_merge($hostHints, $urlData, $page, [
            'listing_url' => $url,
            'source' => $source,
        ]));
    }

    /**
     * @param  array<string, mixed>  $hostHints
     * @param  array<string, mixed>  $urlData
     * @return array<string, mixed>
     */
    private function urlOverridePayload(array $hostHints, array $urlData, string $url): array
    {
        return $this->preferRich(array_merge($hostHints, $urlData, [
            'listing_url' => $url,
            'source' => 'url_slug_override',
            // Do not keep city/coords from the mismatched page shell.
            'city' => $urlData['city'] ?? null,
            'region' => $urlData['region'] ?? null,
            'lat' => null,
            'lng' => null,
        ]));
    }

    private function addressesAgree(string $a, string $b): bool
    {
        $na = $this->normalizeForCompare($a);
        $nb = $this->normalizeForCompare($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if (str_contains($na, $nb) || str_contains($nb, $na)) {
            return true;
        }

        $tokensA = array_values(array_filter(explode(' ', $na), fn ($t) => mb_strlen($t) > 2 && ! preg_match('/^\d+$/', $t)));
        $tokensB = array_values(array_filter(explode(' ', $nb), fn ($t) => mb_strlen($t) > 2 && ! preg_match('/^\d+$/', $t)));
        if ($tokensA === [] || $tokensB === []) {
            return false;
        }

        $overlap = count(array_intersect($tokensA, $tokensB));
        $need = min(2, min(count($tokensA), count($tokensB)));

        return $overlap >= $need;
    }

    private function normalizeForCompare(string $text): string
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/[^a-z0-9\s]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\b(united states|united kingdom|pakistan|australia|japan|street|avenue|road|drive|lane|blvd|boulevard|st|ave|rd|for sale|for rent)\b/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    private function fetchHtml(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $acceptLang = 'en-US,en;q=0.9';
        $cc = $this->urlExtractor->countryFromHost(strtolower(preg_replace('/^www\./', '', $host) ?? $host));
        if (($cc['country_code'] ?? null) === 'GB') {
            $acceptLang = 'en-GB,en;q=0.9';
        } elseif (($cc['country_code'] ?? null) === 'JP') {
            $acceptLang = 'ja,en;q=0.8';
        } elseif (($cc['country_code'] ?? null) === 'DE') {
            $acceptLang = 'de,en;q=0.8';
        } elseif (($cc['country_code'] ?? null) === 'FR') {
            $acceptLang = 'fr,en;q=0.8';
        } elseif (($cc['country_code'] ?? null) === 'ES') {
            $acceptLang = 'es,en;q=0.8';
        } elseif (($cc['country_code'] ?? null) === 'IT') {
            $acceptLang = 'it,en;q=0.8';
        }

        $request = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => $acceptLang,
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Upgrade-Insecure-Requests' => '1',
        ])->timeout(12)->connectTimeout(4);

        if (app()->environment('local')) {
            $request = $request->withOptions(['verify' => false]);
        }

        $response = $request->get($url);

        if (! $response->successful() && ! in_array($response->status(), [403, 503], true)) {
            throw new \RuntimeException('Unable to fetch listing URL (HTTP '.$response->status().').');
        }

        if (! $response->successful() && $response->body() === '') {
            throw new \RuntimeException('Unable to fetch listing URL (HTTP '.$response->status().').');
        }

        return mb_substr($response->body(), 0, 2000000);
    }

    private function looksBlocked(string $html): bool
    {
        $hay = mb_strtolower($html);
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = mb_strtolower(trim(html_entity_decode(strip_tags($m[1]))));
        }

        if (str_contains($title, 'security check')
            || str_contains($title, 'attention required')
            || str_contains($title, 'access denied')
            || str_contains($title, 'just a moment')
            || str_contains($title, 'robot or human')
            || str_contains($title, 'verify you are human')
            || str_contains($title, 'pardon our interruption')
            || str_contains($title, 'are you a robot')
            || str_contains($title, 'blocked')
            || str_contains($title, 'captcha')) {
            return true;
        }

        if (str_contains($hay, 'cf-browser-verification')
            || str_contains($hay, 'cf-challenge-running')
            || str_contains($hay, 'px-captcha')
            || str_contains($hay, 'datadome')
            || str_contains($hay, 'g-recaptcha')) {
            // Only treat as blocked when the page is thin (challenge shell, not a listing with a widget).
            if (mb_strlen(strip_tags($html)) < 2500 || ! str_contains($hay, 'application/ld+json')) {
                return true;
            }
        }

        // Zameen challenge pages are short and titled Security check.
        if (str_contains($hay, 'security check') && str_contains($hay, 'zameen') && ! str_contains($hay, 'application/ld+json')) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFromUrl(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if (str_contains($host, 'zameen.com')) {
            $area = app(AreaKeywordService::class)->resolve($url, null, null, 'PK');
            if (! empty($area['primary']) && strcasecmp($area['primary'], 'Unknown area') !== 0) {
                return array_filter([
                    'address' => $area['geocode_query'] ?? null,
                    'city' => $area['city'] ?? null,
                    'country' => 'Pakistan',
                    'country_code' => 'PK',
                    'title' => $area['primary'],
                    'source' => 'zameen_url',
                    'area_keyword' => $area['primary'],
                ]);
            }
        }

        return $this->urlExtractor->extract($url);
    }

    /**
     * @return array<string, mixed>
     */
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

        // Next.js / Nuxt embedded state used by many modern portals.
        if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            $next = json_decode($m[1], true);
            if (is_array($next)) {
                $this->walkArbitraryJson($next, $result, 0);
            }
        }

        foreach ([
            'locationName', 'area_name', 'neighbourhood', 'neighborhood', 'mapAddress', 'ad_location',
            'locality', 'formattedAddress', 'fullAddress', 'streetAddress', 'displayAddress', 'addressLine',
            'unparsedAddress', 'print_address', 'addressText',
        ] as $key) {
            if (preg_match('/"'.$key.'"\s*:\s*"([^"]{3,200})"/i', $html, $m)) {
                $result['address'] ??= html_entity_decode($m[1]);
            }
        }
        foreach (['city_name', 'city', 'addressLocality', 'townOrCity', 'municipality'] as $key) {
            if (preg_match('/"'.$key.'"\s*:\s*"([^"]{2,80})"/i', $html, $m)) {
                $val = html_entity_decode($m[1]);
                if (! preg_match('/^(true|false|null|\d+)$/i', $val)) {
                    $result['city'] ??= $val;
                }
            }
        }
        if (preg_match('/"country(?:Code|_code)?"\s*:\s*"([A-Za-z]{2})"/', $html, $m)) {
            $result['country_code'] ??= strtoupper($m[1]);
        }

        // Microdata itemprop
        if (preg_match('/itemprop=["\']streetAddress["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/itemprop=["\']streetAddress["\'][^>]*>([^<]+)</i', $html, $m)) {
            $street = trim(html_entity_decode(strip_tags($m[1])));
            $result['address'] ??= $street;
        }
        if (preg_match('/itemprop=["\']addressLocality["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/itemprop=["\']addressLocality["\'][^>]*>([^<]+)</i', $html, $m)) {
            $result['city'] ??= trim(html_entity_decode(strip_tags($m[1])));
        }

        // Open Graph / meta
        if (preg_match_all('/<meta[^>]+(?:property|name)=["\']([^"\']+)["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $metaMatches, PREG_SET_ORDER)) {
            foreach ($metaMatches as $meta) {
                $name = strtolower($meta[1]);
                $content = html_entity_decode($meta[2]);
                if ($name === 'og:title' || $name === 'twitter:title') {
                    $result['title'] ??= $content;
                }
                if (in_array($name, ['og:description', 'description', 'twitter:description'], true)) {
                    $result['description'] ??= $content;
                }
                if ($name === 'og:latitude' || $name === 'place:location:latitude') {
                    $result['lat'] ??= (float) $content;
                }
                if ($name === 'og:longitude' || $name === 'place:location:longitude') {
                    $result['lng'] ??= (float) $content;
                }
                if ($name === 'geo.placename') {
                    $result['city'] ??= $content;
                }
                if ($name === 'geo.region' && strlen($content) <= 10) {
                    $result['region'] ??= $content;
                }
            }
        }

        // Attribute order variants already partially covered; also content-before-property.
        if (preg_match_all('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']([^"\']+)["\']/i', $html, $metaMatches2, PREG_SET_ORDER)) {
            foreach ($metaMatches2 as $meta) {
                $content = html_entity_decode($meta[1]);
                $name = strtolower($meta[2]);
                if ($name === 'og:title') {
                    $result['title'] ??= $content;
                }
                if ($name === 'og:description' || $name === 'description') {
                    $result['description'] ??= $content;
                }
            }
        }

        if (preg_match('/["\'](?:latitude|lat)["\']\s*[:=]\s*([-+]?\d{1,3}\.\d+)/i', $html, $latM)
            && preg_match('/["\'](?:longitude|lng|lon)["\']\s*[:=]\s*([-+]?\d{1,3}\.\d+)/i', $html, $lngM)) {
            $lat = (float) $latM[1];
            $lng = (float) $lngM[1];
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                $result['lat'] ??= $lat;
                $result['lng'] ??= $lng;
            }
        }

        if (empty($result['address']) && ! empty($result['description'])) {
            if (preg_match('/(?:location|area|address|situated in|located in)\s*[:\-–]\s*([^\n\*\.|]{5,100})/i', $result['description'], $m)) {
                $result['address'] = trim($m[1]);
            }
        }

        // H1 often contains the street on Western portals.
        if (empty($result['address']) && preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $h1 = trim(html_entity_decode(strip_tags($m[1])));
            if ($h1 !== '' && preg_match('/\d/', $h1) && mb_strlen($h1) < 120) {
                $result['address'] ??= $h1;
                $result['title'] ??= $h1;
            }
        }

        if (! empty($result['country']) && empty($result['country_code'])) {
            $c = strtoupper((string) $result['country']);
            if (strlen($c) === 2) {
                $result['country_code'] = $c;
            }
        }

        return array_filter($result, fn ($v) => $v !== null && $v !== '');
    }

    /**
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

        $result['title'] ??= is_string($node['name'] ?? null) ? $node['name'] : ($node['headline'] ?? null);
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
            $result['city'] ??= $addr['addressLocality'] ?? $addr['addressRegion'] ?? null;
            $country = $addr['addressCountry'] ?? null;
            if (is_array($country)) {
                $result['country'] ??= $country['name'] ?? null;
                if (! empty($country['name']) && strlen((string) $country['name']) === 2) {
                    $result['country_code'] ??= strtoupper((string) $country['name']);
                }
            } elseif (is_string($country)) {
                if (strlen($country) === 2) {
                    $result['country_code'] ??= strtoupper($country);
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

        foreach (['mainEntity', 'itemOffered', 'about', 'containsPlace', 'offers'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                $this->walkJsonLd($node[$key], $result);
            }
        }

        foreach ($node as $key => $value) {
            if (in_array($key, ['@graph', 'mainEntity'], true)) {
                continue;
            }
            if (is_array($value) && (isset($value['@type']) || isset($value['address']) || isset($value['geo']))) {
                $this->walkJsonLd($value, $result);
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, mixed>  $result
     */
    private function walkArbitraryJson(array $node, array &$result, int $depth): void
    {
        if ($depth > 8) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $k = strtolower($key);
                if (in_array($k, ['formattedaddress', 'fulladdress', 'displayaddress', 'unparsedaddress', 'print_address', 'mapaddress'], true)
                    && mb_strlen($value) > 5) {
                    $result['address'] ??= $value;
                }
                if (in_array($k, ['city', 'cityname', 'addresslocality', 'townorcity'], true) && mb_strlen($value) > 1) {
                    $result['city'] ??= $value;
                }
                if ($k === 'countrycode' && preg_match('/^[A-Za-z]{2}$/', $value)) {
                    $result['country_code'] ??= strtoupper($value);
                }
                if (in_array($k, ['latitude', 'lat'], true) && is_numeric($value)) {
                    $result['lat'] ??= (float) $value;
                }
                if (in_array($k, ['longitude', 'lng', 'lon'], true) && is_numeric($value)) {
                    $result['lng'] ??= (float) $value;
                }
            } elseif (is_array($value)) {
                $this->walkArbitraryJson($value, $result, $depth + 1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preferRich(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function finalize(array $data): array
    {
        if (! empty($data['country_code'])) {
            $data['country_code'] = strtoupper((string) $data['country_code']);
            $data['country'] ??= $this->countryName($data['country_code']);
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function countryName(string $code): ?string
    {
        return match (strtoupper($code)) {
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'PK' => 'Pakistan',
            'AU' => 'Australia',
            'JP' => 'Japan',
            'IN' => 'India',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'PT' => 'Portugal',
            'AE' => 'United Arab Emirates',
            'CA' => 'Canada',
            'NZ' => 'New Zealand',
            'MY' => 'Malaysia',
            'SG' => 'Singapore',
            'NL' => 'Netherlands',
            'IE' => 'Ireland',
            'PL' => 'Poland',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'BE' => 'Belgium',
            'AT' => 'Austria',
            'SA' => 'Saudi Arabia',
            'EG' => 'Egypt',
            default => null,
        };
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
        return isset($data['lat'], $data['lng']) && ! empty($data['address']);
    }
}
