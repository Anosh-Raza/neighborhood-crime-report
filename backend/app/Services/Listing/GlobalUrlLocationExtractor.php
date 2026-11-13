<?php

namespace App\Services\Listing;

/**
 * Infer country + place hints from any property-portal URL when HTML is thin or blocked.
 */
class GlobalUrlLocationExtractor
{
    /**
     * @return array{
     *   address?: string,
     *   city?: string,
     *   country?: string,
     *   country_code?: string,
     *   title?: string,
     *   source: string
     * }
     */
    public function extract(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = urldecode((string) parse_url($url, PHP_URL_PATH));
        $country = $this->countryFromHost($host);

        // Realtor.com has a rigid Street_City_ST_ZIP_M… pattern — parse it directly.
        if (str_contains($host, 'realtor.com')) {
            $realtor = $this->parseRealtorPath($path);
            if ($realtor !== null) {
                return array_filter(array_merge($realtor, [
                    'country' => $country['country'] ?? 'United States',
                    'country_code' => $country['country_code'] ?? 'US',
                    'source' => 'url_slug',
                ]));
            }
        }

        $slug = $this->bestSlugFromPath($host, $path);
        if ($slug === null || $slug === '') {
            return array_filter([
                'country' => $country['country'] ?? null,
                'country_code' => $country['country_code'] ?? null,
                'source' => 'url_host',
            ]);
        }

        $readable = $this->slugToReadable($slug);
        $city = $this->guessCityFromText($readable, $country['country_code'] ?? null);

        // Avoid "City, City, Country" when the readable slug already ends with the city.
        $addressParts = [$readable];
        if ($city && ! preg_match('/\b'.preg_quote($city, '/').'\b/i', $readable)) {
            $addressParts[] = $city;
        }
        if (! empty($country['country'])) {
            $addressParts[] = $country['country'];
        }

        return array_filter([
            'address' => implode(', ', $addressParts),
            'city' => $city,
            'country' => $country['country'] ?? null,
            'country_code' => $country['country_code'] ?? null,
            'title' => $readable,
            'source' => 'url_slug',
        ]);
    }

    /**
     * @return array{address: string, city: string, title: string}|null
     */
    private function parseRealtorPath(string $path): ?array
    {
        if (! preg_match('#realestateandhomes-detail/([^/]+)#i', $path, $m)) {
            return null;
        }

        $seg = urldecode($m[1]);
        // Drop listing id: _M57019-83181
        $seg = preg_replace('/_M\d+(?:-\d+)?$/i', '', $seg) ?? $seg;

        // Street_City_ST_ZIP
        if (! preg_match('/^(.*)_([^_]+)_([A-Za-z]{2})_(\d{5})(?:-\d{4})?$/i', $seg, $p)) {
            return null;
        }

        $street = $this->slugToReadable(str_replace('_', '-', $p[1]));
        $city = $this->slugToReadable(str_replace('_', '-', $p[2]));
        $state = strtoupper($p[3]);
        $zip = $p[4];

        return [
            'address' => "{$street}, {$city}, {$state} {$zip}, United States",
            'city' => $city,
            'title' => "{$street}, {$city}",
        ];
    }

    /**
     * @return array{country?: string, country_code?: string}
     */
    public function countryFromHost(string $host): array
    {
        $map = [
            'zameen.com' => ['Pakistan', 'PK'],
            'olx.com.pk' => ['Pakistan', 'PK'],
            'graana.com' => ['Pakistan', 'PK'],
            'zillow.com' => ['United States', 'US'],
            'realtor.com' => ['United States', 'US'],
            'redfin.com' => ['United States', 'US'],
            'homes.com' => ['United States', 'US'],
            'apartments.com' => ['United States', 'US'],
            'trulia.com' => ['United States', 'US'],
            'rightmove.co.uk' => ['United Kingdom', 'GB'],
            'zoopla.co.uk' => ['United Kingdom', 'GB'],
            'onthemarket.com' => ['United Kingdom', 'GB'],
            'realestate.com.au' => ['Australia', 'AU'],
            'domain.com.au' => ['Australia', 'AU'],
            'suumo.jp' => ['Japan', 'JP'],
            'homes.co.jp' => ['Japan', 'JP'],
            'idealista.com' => ['Spain', 'ES'],
            'idealista.it' => ['Italy', 'IT'],
            'idealista.pt' => ['Portugal', 'PT'],
            'immobiliare.it' => ['Italy', 'IT'],
            'immowelt.de' => ['Germany', 'DE'],
            'immobilienscout24.de' => ['Germany', 'DE'],
            'leboncoin.fr' => ['France', 'FR'],
            'seloger.com' => ['France', 'FR'],
            'funda.nl' => ['Netherlands', 'NL'],
            'propertyguru.com.sg' => ['Singapore', 'SG'],
            'propertyguru.com.my' => ['Malaysia', 'MY'],
            '99acres.com' => ['India', 'IN'],
            'magicbricks.com' => ['India', 'IN'],
            'housing.com' => ['India', 'IN'],
            'makaan.com' => ['India', 'IN'],
            'nobroker.in' => ['India', 'IN'],
            'squareyards.com' => ['India', 'IN'],
            'bayut.com' => ['United Arab Emirates', 'AE'],
            'propertyfinder.ae' => ['United Arab Emirates', 'AE'],
            'dubizzle.com' => ['United Arab Emirates', 'AE'],
            'properstar.com' => [],
            'jamesedition.com' => [],
            'sothebysrealty.com' => [],
            'realtor.ca' => ['Canada', 'CA'],
            'propertyfinder.eg' => ['Egypt', 'EG'],
            'propertyfinder.sa' => ['Saudi Arabia', 'SA'],
            'bayut.sa' => ['Saudi Arabia', 'SA'],
            'olx.in' => ['India', 'IN'],
            'daft.ie' => ['Ireland', 'IE'],
            'fotocasa.es' => ['Spain', 'ES'],
            'habitaclia.com' => ['Spain', 'ES'],
            'immoweb.be' => ['Belgium', 'BE'],
            'willhaben.at' => ['Austria', 'AT'],
            'hemnet.se' => ['Sweden', 'SE'],
            'finn.no' => ['Norway', 'NO'],
            'otodom.pl' => ['Poland', 'PL'],
        ];

        foreach ($map as $domain => $pair) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return [
                    'country' => $pair[0] ?? null,
                    'country_code' => $pair[1] ?? null,
                ];
            }
        }

        // TLD fallbacks
        if (str_ends_with($host, '.pk')) {
            return ['country' => 'Pakistan', 'country_code' => 'PK'];
        }
        if (str_ends_with($host, '.uk') || str_ends_with($host, '.co.uk')) {
            return ['country' => 'United Kingdom', 'country_code' => 'GB'];
        }
        if (str_ends_with($host, '.au') || str_ends_with($host, '.com.au')) {
            return ['country' => 'Australia', 'country_code' => 'AU'];
        }
        if (str_ends_with($host, '.jp')) {
            return ['country' => 'Japan', 'country_code' => 'JP'];
        }
        if (str_ends_with($host, '.in')) {
            return ['country' => 'India', 'country_code' => 'IN'];
        }
        if (str_ends_with($host, '.de')) {
            return ['country' => 'Germany', 'country_code' => 'DE'];
        }
        if (str_ends_with($host, '.fr')) {
            return ['country' => 'France', 'country_code' => 'FR'];
        }
        if (str_ends_with($host, '.it')) {
            return ['country' => 'Italy', 'country_code' => 'IT'];
        }
        if (str_ends_with($host, '.es')) {
            return ['country' => 'Spain', 'country_code' => 'ES'];
        }
        if (str_ends_with($host, '.ae')) {
            return ['country' => 'United Arab Emirates', 'country_code' => 'AE'];
        }
        if (str_ends_with($host, '.ca')) {
            return ['country' => 'Canada', 'country_code' => 'CA'];
        }
        if (str_ends_with($host, '.nz')) {
            return ['country' => 'New Zealand', 'country_code' => 'NZ'];
        }
        if (str_ends_with($host, '.ie')) {
            return ['country' => 'Ireland', 'country_code' => 'IE'];
        }
        if (str_ends_with($host, '.pl')) {
            return ['country' => 'Poland', 'country_code' => 'PL'];
        }
        if (str_ends_with($host, '.se')) {
            return ['country' => 'Sweden', 'country_code' => 'SE'];
        }
        if (str_ends_with($host, '.no')) {
            return ['country' => 'Norway', 'country_code' => 'NO'];
        }
        if (str_ends_with($host, '.be')) {
            return ['country' => 'Belgium', 'country_code' => 'BE'];
        }
        if (str_ends_with($host, '.at')) {
            return ['country' => 'Austria', 'country_code' => 'AT'];
        }
        if (str_ends_with($host, '.sa')) {
            return ['country' => 'Saudi Arabia', 'country_code' => 'SA'];
        }
        if (str_ends_with($host, '.eg')) {
            return ['country' => 'Egypt', 'country_code' => 'EG'];
        }
        if (preg_match('/\.olx\.([a-z.]+)$/', $host, $m)) {
            $cc = strtoupper(explode('.', $m[1])[0] ?? '');
            $names = [
                'PK' => 'Pakistan', 'IN' => 'India', 'PT' => 'Portugal', 'PL' => 'Poland',
                'RO' => 'Romania', 'UA' => 'Ukraine', 'UZ' => 'Uzbekistan',
            ];
            if (isset($names[$cc])) {
                return ['country' => $names[$cc], 'country_code' => $cc];
            }
        }

        return [];
    }

    private function bestSlugFromPath(string $host, string $path): ?string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        // Zillow: /homedetails/123-Main-St-City-ST-12345/id_zpid/
        if (str_contains($host, 'zillow.com') && preg_match('#homedetails/([^/]+)#i', $path, $m)) {
            return preg_replace('/-\d+_zpid$/i', '', $m[1]) ?? $m[1];
        }

        // Realtor: /realestateandhomes-detail/123-Main-St_City_ST_12345_M...
        if (str_contains($host, 'realtor.com') && preg_match('#realestateandhomes-detail/([^/]+)#i', $path, $m)) {
            return $m[1];
        }

        // Redfin: /ST/City/123-Main-St/home/ID
        if (str_contains($host, 'redfin.com') && preg_match('#/([A-Z]{2})/([^/]+)/([^/]+)/home/#', $path, $m)) {
            return $m[3].' '.$m[2].' '.$m[1];
        }

        // Rightmove often lacks address in path — keep null and rely on HTML.
        if (str_contains($host, 'rightmove.co.uk')) {
            return null;
        }

        // Idealista listing detail
        if (str_contains($host, 'idealista.') && preg_match('#/(?:inmueble|immobile|imovel)/([^/]+)#i', $path, $m)) {
            return $m[1];
        }

        // Idealista area pages: venta-viviendas/madrid-madrid → madrid-madrid
        if (str_contains($host, 'idealista.') && preg_match('#(?:^|/)(?:venta|alquiler)[^/]*/([a-z0-9\-]+)#i', $path, $m)) {
            $seg = $m[1];
            if (! preg_match('/^(venta|alquiler)/i', $seg)) {
                return $seg;
            }
        }

        // Realestate.com.au: property-house-nsw-bondi-123 → bondi nsw
        if (str_contains($host, 'realestate.com.au') && preg_match('#property-(?:house|apartment|unit|townhouse|land|villa)-([a-z]{2,3})-([a-z0-9\-]+?)-\d+#i', $path, $m)) {
            return $m[2].' '.$m[1];
        }
        if (str_contains($host, 'realestate.com.au') && preg_match('#property-([^/]+)#i', $path, $m)) {
            return preg_replace('/-\d+$/', '', $m[1]) ?? $m[1];
        }

        // Domain.com.au: /address-suburb-state-postcode-id
        if (str_contains($host, 'domain.com.au') && preg_match('#/([^/]+-\d{4}-\d+)#i', $path, $m)) {
            return preg_replace('/-\d+$/', '', $m[1]) ?? $m[1];
        }

        // Bayut: /property/details-....html sometimes weak; /.../area/...
        if (str_contains($host, 'bayut.') && preg_match('#/(?:for-sale|to-rent)/([^/]+)/([^/]+)#i', $path, $m)) {
            return $m[2].' '.$m[1];
        }

        // PropertyGuru: /listing/... or /property/...
        if (str_contains($host, 'propertyguru.') && preg_match('#/(?:listing|property)/([^/]+)#i', $path, $m)) {
            return $m[1];
        }

        // Zoopla: /for-sale/details/... rarely has address; /property/house/.../city/
        if (str_contains($host, 'zoopla.') && preg_match('#/(?:for-sale|to-rent)/[^/]+/([^/]+)/#i', $path, $m)) {
            return $m[1];
        }

        // Immobiliare.it: /annunci/.../city/...
        if (str_contains($host, 'immobiliare.it') && preg_match('#/annunci/[^/]+/([^/]+)/#i', $path, $m)) {
            return $m[1];
        }

        // Apartments.com /homes.com slug
        if ((str_contains($host, 'apartments.com') || str_contains($host, 'homes.com'))
            && preg_match('#/([^/]+(?:-apartments|-homes)?-[a-z]{2}-\d+)#i', $path, $m)) {
            return $m[1];
        }

        // Zameen
        if (str_contains($host, 'zameen.com') && preg_match('#Property/([^/]+?)(?:-\d+)+#i', $path, $m)) {
            return $m[1];
        }

        // OLX item paths rarely include area — null
        if (str_contains($host, 'olx.')) {
            return null;
        }

        // Generic: longest path segment that looks like an address slug
        $segments = array_values(array_filter(explode('/', $path)));
        $best = null;
        $bestScore = 0;
        foreach ($segments as $seg) {
            if (preg_match('/^(item|property|properties|listing|listings|home|homes|details|search|en|us|uk)$/i', $seg)) {
                continue;
            }
            if (preg_match('/^\d+$/', $seg)) {
                continue;
            }
            $score = substr_count($seg, '-') + substr_count($seg, '_') + (preg_match('/[a-z]/i', $seg) ? 2 : 0);
            if (mb_strlen($seg) > 12) {
                $score += 2;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $seg;
            }
        }

        return $bestScore >= 3 ? $best : null;
    }

    private function slugToReadable(string $slug): string
    {
        $slug = preg_replace('/[_+]+/', '-', $slug) ?? $slug;
        $slug = preg_replace('/\.html?$/i', '', $slug) ?? $slug;
        // Drop trailing marketplace ids
        $slug = preg_replace('/-(?:m\d+|pid\d+|\d{5,}|[a-f0-9]{8,})$/i', '', $slug) ?? $slug;
        $slug = preg_replace('/\b(for-sale|for-rent|sale|rent|house|apartment|flat|condo|plot|commercial)\b/i', ' ', $slug) ?? $slug;
        $slug = str_replace('-', ' ', $slug);
        $slug = trim(preg_replace('/\s+/', ' ', $slug) ?? $slug);

        return implode(' ', array_map(
            fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).mb_substr($w, 1),
            preg_split('/\s+/', mb_strtolower($slug)) ?: []
        ));
    }

    private function guessCityFromText(string $text, ?string $countryCode): ?string
    {
        $clean = trim(preg_replace('/\b(nw|ne|sw|se)\b/i', ' ', $text) ?? $text);
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        if ($countryCode === 'US') {
            $city = $this->guessUsCityBeforeState($clean);
            if ($city) {
                return $city;
            }
        }
        if ($countryCode === 'AU' && preg_match('/\b([A-Za-z][A-Za-z\s]+)\s+(NSW|VIC|QLD|WA|SA|TAS|ACT|NT)\b/i', $text, $m)) {
            return $this->slugToReadable($m[1]);
        }
        if (preg_match('/\b(Madrid|Barcelona|London|Paris|Berlin|Rome|Milan|Dubai|Sydney|Melbourne|Tokyo|Osaka|Mumbai|Delhi|Singapore)\b/i', $text, $m)) {
            return $this->slugToReadable($m[1]);
        }

        return null;
    }

    private function guessUsCityBeforeState(string $text): ?string
    {
        // Drop ZIP so "... City ST 12345" and "... City ST" share one path.
        $text = trim(preg_replace('/\s+\d{5}(?:-\d{4})?\s*$/', '', $text) ?? $text);

        if (preg_match('/\b([A-Za-z][A-Za-z]+)\s+([A-Za-z][A-Za-z]+)\s+([A-Za-z]{2})\s*$/', $text, $m)) {
            if (! $this->isStreetToken($m[1]) && ! $this->isStreetToken($m[2])) {
                return trim($m[1].' '.$m[2]); // Los Angeles
            }

            return trim($m[2]); // Avenue Washington → Washington
        }

        if (preg_match('/\b([A-Za-z][A-Za-z]+)\s+([A-Za-z]{2})\s*$/', $text, $m) && ! $this->isStreetToken($m[1])) {
            return trim($m[1]);
        }

        return null;
    }

    private function isStreetToken(string $word): bool
    {
        return (bool) preg_match('/^(street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct|place|pl|terrace|highway|hwy|pennsylvania|main)$/i', $word);
    }
}
