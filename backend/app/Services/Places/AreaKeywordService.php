<?php

namespace App\Services\Places;

/**
 * Trace a listing URL / address and derive the main area keyword(s)
 * used for Google News search (e.g. Saadi Garden → Saadi Town, DHA → DHA Phase 3).
 */
class AreaKeywordService
{
    /**
     * @return array{
     *   primary: string,
     *   aliases: string[],
     *   search_terms: string[],
     *   locality: ?string,
     *   city: ?string,
     *   country_code: ?string
     * }
     */
    public function resolve(?string $listingUrl, ?string $address, ?string $city = null, ?string $countryCode = null): array
    {
        $hostCountry = $this->countryFromListingHost((string) $listingUrl);
        $parts = $this->partsFromAddress($address, $countryCode ?: ($hostCountry['country_code'] ?? null));
        $fromUrl = $this->partsFromUrl((string) $listingUrl);

        $locality = $fromUrl['locality'] ?? $parts['locality'] ?? null;
        $block = $fromUrl['block'] ?? $parts['block'] ?? null;
        $scheme = $fromUrl['scheme'] ?? $parts['scheme'] ?? null;
        $phase = $fromUrl['phase'] ?? $parts['phase'] ?? null;
        $city = $city ?: ($fromUrl['city'] ?? $parts['city'] ?? null);
        $countryCode = $countryCode
            ?: ($fromUrl['country_code'] ?? null)
            ?: ($parts['country_code'] ?? null)
            ?: ($hostCountry['country_code'] ?? null);

        if ($locality && $this->isJunkLocality($locality)) {
            $locality = null;
        }
        $raw = $locality ?: ($scheme ?: ($city ?: 'Unknown area'));
        if ($this->isJunkLocality($raw)) {
            $raw = $city ?: 'Unknown area';
        }
        $base = $this->normalizeLocality($raw, $phase);

        $aliases = $this->expandAliases($base);
        if ($scheme) {
            $aliases[] = $this->titleCase($scheme);
        }

        $aliases = array_values(array_unique(array_filter(
            $aliases,
            fn ($a) => $a !== '' && mb_strtolower($a) !== mb_strtolower($city ?? '')
        )));

        // Prefer Town only when the listing locality is a Garden twin (not DHA Phase, etc.).
        $primary = $base;
        if (preg_match('/\bgarden\b/i', $base)) {
            foreach ($aliases as $alias) {
                if (preg_match('/\btown\b/i', $alias)) {
                    $primary = $alias;
                    break;
                }
            }
        }

        $countryCode = $countryCode ? strtoupper($countryCode) : null;
        // US "Washington Dc" → "Washington"
        if ($countryCode === 'US' && preg_match('/^(.+?)\s+[A-Za-z]{2}$/', $primary, $sm)) {
            $primary = trim($sm[1]);
            $base = $primary;
        }
        if ($city && preg_match('/^'.preg_quote($city, '/').'\b/i', (string) $primary)) {
            // Prefer clean city keyword when locality is "City ST" noise.
            if (preg_match('/^'.preg_quote($city, '/').'\s+[A-Za-z]{2}$/i', $primary)) {
                $primary = $city;
            }
        }

        $searchTerms = array_values(array_unique(array_filter(array_merge([$primary, $base], $aliases))));
        // Add distinctive place tokens so news search matches "Pocharam" for "Pocharam Secunderabad".
        foreach (preg_split('/\s+/', $primary) ?: [] as $token) {
            $token = trim($token);
            $low = mb_strtolower($token);
            if (mb_strlen($token) < 4) {
                continue;
            }
            if (in_array($low, ['phase', 'block', 'town', 'garden', 'society', 'colony', 'sector', 'scheme', 'near', 'area'], true)) {
                continue;
            }
            $searchTerms[] = $this->titleCase($token);
        }
        $searchTerms = array_values(array_unique(array_filter($searchTerms)));
        $countryName = $this->countryName($countryCode);

        $geoParts = [];
        if ($base && strcasecmp($base, 'Unknown area') !== 0) {
            $geoParts[] = $base;
        }
        if ($city && (! $base || mb_strtolower($city) !== mb_strtolower($base)) && ! ($base && str_contains(mb_strtolower($base), mb_strtolower($city)))) {
            $geoParts[] = $city;
        }
        if ($countryName) {
            $geoParts[] = $countryName;
        }

        return [
            'primary' => $primary,
            'aliases' => array_values(array_filter($aliases, fn ($a) => mb_strtolower($a) !== mb_strtolower($primary))),
            'search_terms' => $searchTerms,
            'locality' => $base,
            'block' => $block,
            'scheme' => $scheme ? $this->titleCase($scheme) : null,
            'phase' => $phase,
            'city' => $city,
            'country_code' => $countryCode,
            'geocode_query' => implode(', ', $geoParts),
        ];
    }

    private function countryName(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

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

    /**
     * Shared cleaner for Zameen slug / address fragments.
     */
    public function normalizeLocality(string $text, ?string $phase = null): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[_]+/', ' ', $t) ?? $t;
        $t = preg_replace('/\s*[-–]\s*/', ' ', $t) ?? $t;
        $t = preg_replace('/\b(for sale|for rent|commercial|residential|plot|house|flat|apartment|bedroom|sqyd|sqyds|marla|kanal|\d+\s*ft|road facing)\b/i', ' ', $t) ?? $t;
        // Block B / Block B 5 / Block 6
        $t = preg_replace('/\bblock\s*[a-z0-9]+(?:\s*[a-z0-9]+)?\b/i', ' ', $t) ?? $t;
        $t = trim(preg_replace('/\s+/', ' ', $t) ?? $t);
        $t = $this->collapseRepeatedPhrases($t);

        if ($phase === null && preg_match('/\bphase\s*([0-9]+|[ivxlcdm]+)\b/i', $t, $pm)) {
            $phase = $this->normalizePhase($pm[1]);
        } elseif ($phase !== null) {
            $phase = $this->normalizePhase($phase);
        }

        // DHA / Defence Housing Authority → clean "DHA Phase N"
        if (preg_match('/\b(dha|defence housing authority|defense housing authority)\b/i', $t)) {
            if ($phase) {
                return 'DHA Phase '.$phase;
            }

            return 'DHA';
        }

        // Bahria Town Phase N
        if (preg_match('/\bbahria\b/i', $t)) {
            if ($phase) {
                return 'Bahria Town Phase '.$phase;
            }

            return 'Bahria Town';
        }

        // Saadi Garden / Town
        if (preg_match('/\bsaadi\b/i', $t)) {
            if (preg_match('/\btown\b/i', $t)) {
                return 'Saadi Town';
            }

            return 'Saadi Garden';
        }

        // Gulistan-e-Jauhar / Gulshan variants
        if (preg_match('/\bgulistan[\s\-]*e[\s\-]*jauhar\b/i', $t) || preg_match('/\bgulistan\s+e\s+jauhar\b/i', $t)) {
            return 'Gulistan-e-Jauhar';
        }
        if (preg_match('/\bgulshan[\s\-]*e[\s\-]*iqbal\b/i', $t)) {
            return 'Gulshan-e-Iqbal';
        }
        if (preg_match('/\bgulshan\b/i', $t)) {
            return 'Gulshan';
        }

        if ($phase && ! preg_match('/\bphase\b/i', $t)) {
            $t .= ' phase '.$phase;
        }

        return $this->titleCase($this->collapseRepeatedPhrases($t));
    }

    /**
     * @return array{locality?: string, block?: string, scheme?: string, phase?: string, city?: string, country_code?: string}
     */
    private function partsFromUrl(string $url): array
    {
        if ($url === '') {
            return [];
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $path = urldecode((string) parse_url($url, PHP_URL_PATH));

        if (! str_contains($host, 'zameen.com')) {
            return [];
        }

        if (! preg_match('#/Property/([^/]+?)(?:-\d+)+#i', $path, $m) && ! preg_match('#/Property/([^/?]+)#i', $path, $m)) {
            return [];
        }

        $slug = preg_replace('/_+/', ' ', $m[1]) ?? $m[1];
        $slug = preg_replace('/\s*-\s*/', ' ', $slug) ?? $slug;
        $slug = preg_replace('/\b(for sale|for rent|commercial|residential|plot|house|flat|apartment|bedroom|sqyd|sqyds|marla|kanal|\d+\s*ft|road facing).*$/i', '', $slug) ?? $slug;
        $slug = trim(preg_replace('/\s+/', ' ', mb_strtolower($slug)) ?? $slug);
        $slug = $this->collapseRepeatedPhrases($slug);

        $out = ['country_code' => 'PK'];
        if (preg_match('/\bblock\s*([a-z0-9]+)(?:\s+([a-z0-9]+))?\b/i', $slug, $bm)) {
            $out['block'] = 'Block '.strtoupper($bm[1]).(isset($bm[2]) ? ' '.$bm[2] : '');
        }
        if (preg_match('/\bscheme\s*(\d+)\b/i', $slug, $sm)) {
            $out['scheme'] = 'Scheme '.$sm[1];
        }
        if (preg_match('/\bphase\s*([0-9]+|[ivxlcdm]+)\b/i', $slug, $pm)) {
            $out['phase'] = $this->normalizePhase($pm[1]);
        }

        $locality = preg_replace('/\bblock\s*[a-z0-9]+(?:\s+[a-z0-9]+)?\b.*$/i', '', $slug) ?? $slug;
        $locality = trim($locality);
        if ($locality !== '') {
            $out['locality'] = $locality;
        }

        $out['city'] = $this->guessCity($slug.' '.$locality, 'PK') ?? null;
        $out = array_filter($out, fn ($v) => $v !== null && $v !== '');

        return $out;
    }

    /**
     * @return array{locality?: string, block?: string, scheme?: string, phase?: string, city?: string, country_code?: string}
     */
    private function partsFromAddress(?string $address, ?string $countryHint = null): array
    {
        if (! $address) {
            return [];
        }

        $raw = trim($address);
        $chunks = array_values(array_filter(array_map('trim', preg_split('/,/', $raw) ?: [])));
        $out = [];
        if ($countryHint) {
            $out['country_code'] = strtoupper($countryHint);
        }

        foreach ($chunks as $chunk) {
            $low = mb_strtolower($chunk);
            if (preg_match('/\bblock\s*([a-z0-9]+)(?:\s+([a-z0-9]+))?\b/i', $chunk, $bm)) {
                $out['block'] = 'Block '.strtoupper($bm[1]).(isset($bm[2]) ? ' '.$bm[2] : '');
            }
            if (preg_match('/\bscheme\s*(\d+)\b/i', $chunk, $sm)) {
                $out['scheme'] = 'Scheme '.$sm[1];
            }
            if (preg_match('/\bphase\s*([0-9]+|[ivxlcdm]+)\b/i', $chunk, $pm)) {
                $out['phase'] = $this->normalizePhase($pm[1]);
            }
            if (
                preg_match('/\b(garden|town|society|phase|colony|housing|dha|defence|bahria)\b/i', $chunk)
                && empty($out['locality'])
            ) {
                $loc = preg_replace('/\s*[-–]\s*block.*$/i', '', $chunk) ?? $chunk;
                $loc = preg_replace('/\bblock\s*[a-z0-9]+(?:\s+[a-z0-9]+)?\b/i', '', $loc) ?? $loc;
                $out['locality'] = trim($loc);
            }
            if (in_array($low, ['karachi', 'lahore', 'islamabad', 'rawalpindi', 'peshawar', 'multan', 'faisalabad'], true)) {
                $out['city'] = ucfirst($low);
            }
            if (in_array($low, ['pakistan', 'پاکستان', 'sindh', 'punjab'], true)) {
                $out['country_code'] = 'PK';
            }
            if (in_array($low, ['united states', 'usa', 'u.s.', 'u.s.a.'], true)) {
                $out['country_code'] = 'US';
            }
            if (in_array($low, ['united kingdom', 'uk', 'england', 'scotland', 'wales'], true)) {
                $out['country_code'] = 'GB';
            }
            if (in_array($low, ['australia'], true)) {
                $out['country_code'] = 'AU';
            }
            if (in_array($low, ['japan'], true)) {
                $out['country_code'] = 'JP';
            }
            if (in_array($low, ['india'], true)) {
                $out['country_code'] = 'IN';
            }
            if (in_array($low, ['united arab emirates', 'uae'], true)) {
                $out['country_code'] = 'AE';
            }
            if (in_array($low, ['canada'], true)) {
                $out['country_code'] = 'CA';
            }
        }

        // US-style city before state code (ignore street directionals / types).
        if (empty($out['city'])) {
            $compact = trim(preg_replace('/\b(nw|ne|sw|se)\b/i', ' ', $raw) ?? $raw);
            $compact = trim(preg_replace('/\s+/', ' ', $compact) ?? $compact);
            $compact = trim(preg_replace('/\s+\d{5}(?:-\d{4})?\s*$/', '', $compact) ?? $compact);
            $street = '/^(street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct|place|pl|terrace|pennsylvania|main)$/i';
            if (preg_match('/\b([A-Za-z][A-Za-z]+)\s+([A-Za-z][A-Za-z]+)\s+([A-Za-z]{2})\s*$/', $compact, $um)) {
                $city = (! preg_match($street, $um[1]) && ! preg_match($street, $um[2]))
                    ? trim($um[1].' '.$um[2])
                    : trim($um[2]);
                $out['city'] = $this->titleCase($city);
                $out['country_code'] ??= 'US';
                $out['locality'] ??= $out['city'];
            } elseif (preg_match('/\b([A-Za-z][A-Za-z]+)\s+([A-Za-z]{2})\s*$/', $compact, $um2) && ! preg_match($street, $um2[1])) {
                $out['city'] = $this->titleCase(trim($um2[1]));
                $out['country_code'] ??= 'US';
                $out['locality'] ??= $out['city'];
            }
        }

        // UK postcode city hint: "..., London, SW1A 1AA"
        if (empty($out['city']) && preg_match('/\b([A-Za-z][A-Za-z\s\-]+),\s*[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', $raw, $ukm)) {
            $out['city'] = $this->titleCase(trim($ukm[1]));
            $out['country_code'] ??= 'GB';
            $out['locality'] ??= $out['city'];
        }

        if (empty($out['locality']) && $chunks !== []) {
            $first = preg_replace('/\s*[-–]\s*block.*$/i', '', $chunks[0]) ?? $chunks[0];
            $first = preg_replace('/\bblock\s*[a-z0-9]+(?:\s+[a-z0-9]+)?\b/i', '', $first) ?? $first;
            $first = trim($first);
            if ($first !== '' && ! preg_match('/^\d/', $first)) {
                $out['locality'] = $first;
            } elseif ($first !== '' && preg_match('/^\d/', $first) && ! empty($out['city'])) {
                // Street number address → use city/neighborhood as the news keyword.
                $out['locality'] = $out['city'];
            } elseif ($first !== '' && preg_match('/^\d/', $first)) {
                // Pull trailing place tokens from a single-line street address.
                $place = $this->trailingPlaceFromStreet($first);
                if ($place) {
                    $out['locality'] = $place;
                    $out['city'] ??= $place;
                }
            }
        }

        if (empty($out['city'])) {
            $out['city'] = $this->guessCity($raw, $out['country_code'] ?? $countryHint);
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array{country?: string, country_code?: string}
     */
    private function countryFromListingHost(string $url): array
    {
        if ($url === '') {
            return [];
        }

        try {
            return app(\App\Services\Listing\GlobalUrlLocationExtractor::class)
                ->countryFromHost(preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '');
        } catch (\Throwable) {
            return [];
        }
    }

    private function trailingPlaceFromStreet(string $street): ?string
    {
        $street = preg_replace('/\b(united states|united kingdom|pakistan|australia|japan|india)\b/i', '', $street) ?? $street;
        $street = trim(preg_replace('/\s+/', ' ', $street) ?? $street);
        // Drop leading house number + street type words; keep last 1–3 capitalized place tokens.
        $street = preg_replace('/^\d+[A-Za-z]?\s+/', '', $street) ?? $street;
        $street = preg_replace('/\b(street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct|place|pl|terrace|nw|ne|sw|se)\b/i', ' ', $street) ?? $street;
        $street = trim(preg_replace('/\s+/', ' ', $street) ?? $street);
        $words = preg_split('/\s+/', $street) ?: [];
        if ($words === []) {
            return null;
        }
        $tail = array_slice($words, -2);
        $place = trim(implode(' ', $tail));

        return $place !== '' ? $this->titleCase($place) : null;
    }

    /**
     * @return string[]
     */
    private function expandAliases(string $base): array
    {
        $aliases = [];
        $low = mb_strtolower($base);

        if (preg_match('/^dha\s+phase\s+(.+)$/i', $base, $m)) {
            $phase = $m[1];
            $aliases[] = 'DHA Phase '.$phase;
            $aliases[] = 'DHA Defence Phase '.$phase;
            $aliases[] = 'Defence Phase '.$phase;
            $aliases[] = 'DHA Karachi Phase '.$phase;
        } elseif ($low === 'dha') {
            $aliases[] = 'DHA Karachi';
            $aliases[] = 'Defence Housing Authority';
        }

        if (preg_match('/^(.+?)\s+garden$/i', $base, $m)) {
            $stem = trim($m[1]);
            $aliases[] = $stem.' Town';
            $aliases[] = $stem.' Society';
            $aliases[] = $stem.' Garden';
        } elseif (preg_match('/^(.+?)\s+town$/i', $base, $m)) {
            $stem = trim($m[1]);
            $aliases[] = $stem.' Garden';
            $aliases[] = $stem.' Society';
            $aliases[] = $stem.' Town';
        } elseif (preg_match('/^(.+?)\s+society$/i', $base, $m)) {
            $stem = trim($m[1]);
            $aliases[] = $stem.' Town';
            $aliases[] = $stem.' Garden';
        }

        if (str_contains($low, 'saadi')) {
            $aliases[] = 'Saadi Town';
            $aliases[] = 'Saadi Garden';
        }

        if (str_contains($low, 'bahria')) {
            $aliases[] = 'Bahria Town';
            $aliases[] = 'Bahria Town Karachi';
        }

        return array_map(fn ($a) => $this->titleCasePreservingAcronyms($a), $aliases);
    }

    private function collapseRepeatedPhrases(string $text): string
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($text))) ?: [];
        if (count($words) < 2) {
            return trim($text);
        }

        // Exact consecutive repeat: "a b a b" or "a b c a b c"
        $joined = implode(' ', $words);
        if (preg_match('/^(.+?)\s+\1(\s+.+)?$/u', $joined, $m)) {
            $joined = trim($m[1].($m[2] ?? ''));
            $words = preg_split('/\s+/', $joined) ?: [];
        }

        // Partial overlap: "dha defence phase 3 dha phase 3" → keep through first phase N
        if (preg_match('/\bphase\s*([0-9]+|[ivxlcdm]+)\b/i', $joined, $pm, PREG_OFFSET_CAPTURE)) {
            $phaseWord = $pm[0][0];
            $pos = (int) $pm[0][1];
            $before = trim(mb_substr($joined, 0, $pos + mb_strlen($phaseWord)));
            // If "phase N" appears twice, cut after first.
            $phaseCount = preg_match_all('/\bphase\s*([0-9]+|[ivxlcdm]+)\b/i', $joined);
            if ($phaseCount > 1 && $before !== '') {
                $joined = $before;
                $words = preg_split('/\s+/', $joined) ?: [];
            }
        }

        // Drop duplicate tokens while keeping order (dha dha phase phase 3 → dha phase 3)
        $out = [];
        $prev = null;
        foreach ($words as $w) {
            if ($w === $prev) {
                continue;
            }
            $out[] = $w;
            $prev = $w;
        }

        // Remove redundant "defence" when "dha" already present before phase
        $joined = implode(' ', $out);
        $joined = preg_replace('/\bdha\s+defence\s+(?=phase\b)/i', 'dha ', $joined) ?? $joined;
        $joined = preg_replace('/\bdha\s+phase\s+dha\s+phase/i', 'dha phase', $joined) ?? $joined;

        return trim(preg_replace('/\s+/', ' ', $joined) ?? $joined);
    }

    private function isJunkLocality(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/^(venta|alquiler|immobile|inmueble|imovel|for sale|for rent|properties|property|homes|listings?|unknown area)(\s|$)/i', $t)
            || (bool) preg_match('/\b(venta viviendas|alquiler viviendas)\b/i', $t);
    }

    private function normalizePhase(string $phase): string
    {
        $phase = trim($phase);
        if (preg_match('/^\d+$/', $phase)) {
            return $phase;
        }

        return strtoupper($phase);
    }

    private function guessCity(string $text, ?string $countryCode = null): ?string
    {
        $s = mb_strtolower($text);
        foreach (['karachi', 'lahore', 'islamabad', 'rawalpindi', 'peshawar', 'multan', 'faisalabad'] as $c) {
            if (str_contains($s, $c)) {
                return ucfirst($c);
            }
        }
        foreach (['london', 'manchester', 'birmingham', 'leeds', 'glasgow', 'edinburgh'] as $c) {
            if (str_contains($s, $c)) {
                return $this->titleCase($c);
            }
        }
        foreach (['sydney', 'melbourne', 'brisbane', 'perth', 'adelaide'] as $c) {
            if (str_contains($s, $c)) {
                return $this->titleCase($c);
            }
        }
        foreach (['dubai', 'abu dhabi', 'sharjah'] as $c) {
            if (str_contains($s, $c)) {
                return $this->titleCase($c);
            }
        }
        foreach (['new york', 'los angeles', 'chicago', 'houston', 'miami', 'seattle', 'boston', 'washington', 'san francisco', 'austin', 'denver', 'atlanta'] as $c) {
            if (str_contains($s, $c)) {
                return $this->titleCase($c);
            }
        }

        $cc = $countryCode ? strtoupper($countryCode) : null;
        // Only bias Pakistani housing schemes to Karachi when country is PK / unknown.
        if ($cc === null || $cc === 'PK') {
            foreach (['saadi', 'bahria', 'gulshan', 'clifton', 'nazimabad', 'gulistan', 'scheme', 'dha', 'defence'] as $hint) {
                if (str_contains($s, $hint)) {
                    return 'Karachi';
                }
            }
        }

        return null;
    }

    private function titleCase(string $text): string
    {
        return $this->titleCasePreservingAcronyms($text);
    }

    private function titleCasePreservingAcronyms(string $text): string
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($text))) ?: [];

        return implode(' ', array_map(function ($w) {
            if (in_array($w, ['dha', 'pk', 'uae', 'uk', 'usa'], true)) {
                return mb_strtoupper($w);
            }

            return mb_strtoupper(mb_substr($w, 0, 1)).mb_substr($w, 1);
        }, $words));
    }
}
